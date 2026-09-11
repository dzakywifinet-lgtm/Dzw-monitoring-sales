<?php
/**
 * Penyimpanan file JSON di server web (bukan di router) untuk pengaturan FUP
 * dan pelacakan pemakaian bandwidth bulanan/siklus.
 *
 * Ditulis secara ATOMIK (tulis ke file sementara lalu rename) supaya kalau
 * listrik/koneksi putus di tengah proses tulis, file utama tidak ikut rusak
 * atau kosong - rename di filesystem POSIX bersifat all-or-nothing.
 * Sebuah salinan cadangan (.bak) juga disimpan sebagai jaring pengaman kedua.
 */

define('MH_DATA_DIR', __DIR__ . '/../data');

function mh_data_path($name)
{
    if (!is_dir(MH_DATA_DIR)) {
        @mkdir(MH_DATA_DIR, 0755, true);
    }
    return MH_DATA_DIR . '/' . $name;
}

function mh_read_json_file($path)
{
    if (!file_exists($path)) {
        return null;
    }
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return null;
    }
    flock($fh, LOCK_SH);
    $content = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

function mh_read_json($name, $default)
{
    $path = mh_data_path($name);
    $data = mh_read_json_file($path);
    if ($data !== null) {
        return $data;
    }
    // File utama hilang/rusak (mis. karena mati listrik) - coba pakai cadangan.
    $data = mh_read_json_file($path . '.bak');
    if ($data !== null) {
        return $data;
    }
    return $default;
}

function mh_write_json($name, $data)
{
    $path = mh_data_path($name);
    $tmp = $path . '.tmp';

    $fh = @fopen($tmp, 'w');
    if (!$fh) {
        return false;
    }
    flock($fh, LOCK_EX);
    fwrite($fh, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    if (file_exists($path)) {
        @copy($path, $path . '.bak');
    }
    // rename() atomik di filesystem POSIX: file utama tidak pernah dalam
    // keadaan "separuh tertulis" walau proses terhenti mendadak.
    return @rename($tmp, $path);
}

function mh_settings_key($host)
{
    return preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $host);
}

/** Ambil pengaturan FUP (kuota, interface WAN, tanggal reset) + Telegram untuk satu router. */
function mh_get_settings($host)
{
    $all = mh_read_json('settings.json', array());
    $key = mh_settings_key($host);
    $defaults = array(
        'quota_gb' => 0, 'interface' => '', 'reset_day' => 1, 'static_quota_gb' => 0,
        'telegram_enabled' => false, 'telegram_token' => '', 'telegram_chat_id' => '',
        'cron_enabled' => false, 'cron_token' => '',
        // Profil voucher untuk cetak (dipakai semua layout print di popup Voucher).
        'voucher_hotspot_name' => '', 'voucher_login_address' => '',
        'voucher_footer_note' => '', 'voucher_logo' => '',
    );
    return isset($all[$key]) ? array_merge($defaults, $all[$key]) : $defaults;
}

function mh_save_settings($host, $settings)
{
    $all = mh_read_json('settings.json', array());
    $key = mh_settings_key($host);
    $current = isset($all[$key]) ? $all[$key] : array();
    $all[$key] = array_merge($current, $settings);
    return mh_write_json('settings.json', $all);
}

/** Daftar template cetak voucher custom (HTML) yang sudah disimpan admin. */
function mh_get_voucher_templates($host)
{
    $all = mh_read_json('voucher_templates.json', array());
    $key = mh_settings_key($host);
    return (isset($all[$key]) && is_array($all[$key])) ? $all[$key] : array();
}

/** Simpan (atau timpa kalau nama sama) satu template cetak voucher custom. */
function mh_save_voucher_template($host, $name, $html)
{
    $all = mh_read_json('voucher_templates.json', array());
    $key = mh_settings_key($host);
    if (!isset($all[$key]) || !is_array($all[$key])) {
        $all[$key] = array();
    }
    $found = false;
    foreach ($all[$key] as &$t) {
        if ($t['name'] === $name) {
            $t['html'] = $html;
            $found = true;
            break;
        }
    }
    unset($t);
    if (!$found) {
        $all[$key][] = array('name' => $name, 'html' => $html);
    }
    return mh_write_json('voucher_templates.json', $all);
}

/** Hapus satu template cetak voucher custom berdasar nama. */
function mh_delete_voucher_template($host, $name)
{
    $all = mh_read_json('voucher_templates.json', array());
    $key = mh_settings_key($host);
    if (isset($all[$key]) && is_array($all[$key])) {
        $all[$key] = array_values(array_filter($all[$key], function ($t) use ($name) {
            return $t['name'] !== $name;
        }));
    }
    return mh_write_json('voucher_templates.json', $all);
}

function mh_default_bandwidth_state()
{
    return array(
        'last_rx' => null,
        'last_tx' => null,
        'cycle_start' => '',
        'cycle_rx_bytes' => 0,
        'cycle_tx_bytes' => 0,
        'sync_offset_bytes' => 0,
        'last_update' => '',
        'history' => array(),
    );
}

/**
 * Perbarui akumulasi bandwidth siklus berjalan dari counter interface WAN.
 * Aman terhadap reset counter (mis. router reboot) dan otomatis mulai
 * siklus baru saat melewati tanggal reset yang diatur.
 *
 * $iface: array('rx'=>..,'tx'=>..) atau null kalau interface belum diatur/tidak ditemukan.
 */
function mh_track_bandwidth($host, $iface, $resetDay)
{
    $all = mh_read_json('bandwidth.json', array());
    $key = mh_settings_key($host);
    $state = isset($all[$key]) ? array_merge(mh_default_bandwidth_state(), $all[$key]) : mh_default_bandwidth_state();

    $cycleStart = mh_cycle_start($resetDay);
    if ($state['cycle_start'] !== $cycleStart) {
        if ($state['cycle_start'] !== '') {
            $state['history'][$state['cycle_start']] = array(
                'rx'    => $state['cycle_rx_bytes'],
                'tx'    => $state['cycle_tx_bytes'],
                'total' => $state['cycle_rx_bytes'] + $state['cycle_tx_bytes'] + $state['sync_offset_bytes'],
            );
            if (count($state['history']) > 12) {
                $state['history'] = array_slice($state['history'], -12, null, true);
            }
        }
        $state['cycle_start'] = $cycleStart;
        $state['cycle_rx_bytes'] = 0;
        $state['cycle_tx_bytes'] = 0;
        $state['sync_offset_bytes'] = 0;
    }

    if ($iface !== null) {
        if ($state['last_rx'] !== null && $state['last_tx'] !== null) {
            $deltaRx = $iface['rx'] >= $state['last_rx'] ? $iface['rx'] - $state['last_rx'] : $iface['rx'];
            $deltaTx = $iface['tx'] >= $state['last_tx'] ? $iface['tx'] - $state['last_tx'] : $iface['tx'];
            $state['cycle_rx_bytes'] += max(0, $deltaRx);
            $state['cycle_tx_bytes'] += max(0, $deltaTx);
        }
        $state['last_rx'] = $iface['rx'];
        $state['last_tx'] = $iface['tx'];
        $state['last_update'] = date('Y-m-d H:i:s');
    }

    $all[$key] = $state;
    mh_write_json('bandwidth.json', $all);

    return $state;
}

/**
 * ---------- MODE CRON: kredensial router terenkripsi ----------
 * Dipakai supaya cron.php bisa jalan tanpa sesi browser (dipanggil dari crontab
 * server). Username & password disimpan di data/cron_creds.json dalam bentuk
 * TERENKRIPSI (AES-256-CBC, IV acak per baris), kuncinya di data/.cronkey.
 * Kedua file sudah diblokir akses langsung oleh data/.htaccess ("Deny from all"
 * berlaku untuk seluruh folder data/).
 */

/** Ambil (atau buat sekali) kunci enkripsi 256-bit lokal untuk kredensial cron. */
function mh_cron_get_key()
{
    $path = mh_data_path('.cronkey');
    if (file_exists($path)) {
        $raw = @file_get_contents($path);
        $key = $raw !== false ? base64_decode(trim($raw), true) : false;
        if ($key !== false && strlen($key) === 32) {
            return $key;
        }
    }
    $key = random_bytes(32);
    @file_put_contents($path, base64_encode($key));
    @chmod($path, 0600);
    return $key;
}

function mh_cron_encrypt($plaintext, $key)
{
    if (!function_exists('openssl_encrypt')) {
        return false;
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt((string) $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return false;
    }
    return base64_encode($iv . $cipher);
}

function mh_cron_decrypt($blob, $key)
{
    if (!function_exists('openssl_decrypt')) {
        return false;
    }
    $raw = base64_decode((string) $blob, true);
    if ($raw === false || strlen($raw) <= 16) {
        return false;
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? false : $plain;
}

/** Simpan (menimpa) kredensial router terenkripsi untuk satu host, dipakai Mode Cron. */
function mh_save_cron_creds($host, $user, $pass, $port, $ssl)
{
    $key = mh_cron_get_key();
    $payload = json_encode(array(
        'host' => (string) $host,
        'user' => (string) $user,
        'pass' => (string) $pass,
        'port' => (int) $port,
        'ssl'  => (bool) $ssl,
    ));
    $enc = mh_cron_encrypt($payload, $key);
    if ($enc === false) {
        return false;
    }
    $all = mh_read_json('cron_creds.json', array());
    $all[mh_settings_key($host)] = $enc;
    return mh_write_json('cron_creds.json', $all);
}

/** Baca & dekripsi kredensial cron satu host. Mengembalikan null kalau tidak ada/rusak. */
function mh_get_cron_creds($host)
{
    $all = mh_read_json('cron_creds.json', array());
    $k = mh_settings_key($host);
    if (!isset($all[$k])) {
        return null;
    }
    $plain = mh_cron_decrypt($all[$k], mh_cron_get_key());
    if ($plain === false) {
        return null;
    }
    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

/** Hapus kredensial cron satu host secara langsung (dipakai tombol "Nonaktifkan & Hapus Kredensial"). */
function mh_delete_cron_creds($host)
{
    $all = mh_read_json('cron_creds.json', array());
    $k = mh_settings_key($host);
    if (isset($all[$k])) {
        unset($all[$k]);
        mh_write_json('cron_creds.json', $all);
    }
    return true;
}

/** Token rahasia acak untuk endpoint cron.php (dipakai lewat query string, bukan sesi login). */
function mh_generate_cron_token()
{
    return bin2hex(random_bytes(20));
}

function mh_default_usage_state()
{
    return array(
        'cycle_start' => '',
        'last_raw'    => array(),
        'cycle_bytes' => array(),
        'last_update' => '',
    );
}

/**
 * Akumulasi byte per kategori pemakaian (video/google/wa/dst), dibaca dari
 * counter mangle rule di router. Sama seperti mh_track_bandwidth: aman
 * terhadap reset counter (reboot / rule dibuat ulang), dan siklus mengikuti
 * tanggal reset FUP yang sama supaya sinkron dengan kartu bandwidth.
 *
 * $rawBytes: array('video'=>123, 'google'=>456, ...) nilai counter MENTAH
 * dari router saat ini, atau null kalau tidak ada data baru (hanya baca state).
 */
function mh_track_usage_categories($host, $rawBytes, $resetDay)
{
    $all = mh_read_json('usage_categories.json', array());
    $key = mh_settings_key($host);
    $state = isset($all[$key]) ? array_merge(mh_default_usage_state(), $all[$key]) : mh_default_usage_state();

    $cycleStart = mh_cycle_start($resetDay);
    if ($state['cycle_start'] !== $cycleStart) {
        $state['cycle_start'] = $cycleStart;
        $state['cycle_bytes'] = array();
        // last_raw TIDAK direset - dipakai untuk hitung delta counter berikutnya.
    }

    if (is_array($rawBytes)) {
        foreach ($rawBytes as $cat => $val) {
            $val = (float) $val;
            $last = isset($state['last_raw'][$cat]) ? $state['last_raw'][$cat] : null;
            $prevCycle = isset($state['cycle_bytes'][$cat]) ? $state['cycle_bytes'][$cat] : 0;
            if ($last !== null) {
                $delta = $val >= $last ? $val - $last : $val; // counter turun = rule kena reset/reboot
                $prevCycle += max(0, $delta);
            }
            $state['cycle_bytes'][$cat] = $prevCycle;
            $state['last_raw'][$cat] = $val;
        }
        $state['last_update'] = date('Y-m-d H:i:s');
    }

    $all[$key] = $state;
    mh_write_json('usage_categories.json', $all);

    return $state;
}

/**
 * Sinkronisasi manual: samakan akumulasi berjalan dengan angka resmi dari
 * myIndiHome/MyTelkomsel. Pemakaian berikutnya tetap dihitung otomatis dari
 * Mikrotik, ditambah offset ini.
 */
/**
 * ---------- CACHE HALAMAN DASHBOARD (biar buka dashboard tidak menunggu router) ----------
 * Setiap kali mh_build_light()/mh_build_sales() berhasil jalan - baik dari poll
 * biasa (api/live.php, api/sales.php), dari cron.php, maupun dari pemuatan
 * pertama - hasilnya digabung & disimpan di sini. index.php lalu MEMBACA cache
 * ini saja (tanpa menghubungi router sama sekali) supaya halaman langsung
 * tampil begitu diklik dari login atau dari tombol "Kembali ke dashboard" di
 * Pengaturan. Data yang sedikit basi (maksimal seumur interval poll) langsung
 * disegarkan oleh JavaScript begitu halaman tampil.
 */
function mh_get_dash_cache($host)
{
    $all = mh_read_json('dash_cache.json', array());
    $key = mh_settings_key($host);
    return isset($all[$key]) ? $all[$key] : null;
}

function mh_update_dash_cache($host, $partial)
{
    $all = mh_read_json('dash_cache.json', array());
    $key = mh_settings_key($host);
    $current = isset($all[$key]) ? $all[$key] : array();
    $all[$key] = array_merge($current, $partial);
    return mh_write_json('dash_cache.json', $all);
}

function mh_sync_bandwidth($host, $currentUsageGb)
{
    $all = mh_read_json('bandwidth.json', array());
    $key = mh_settings_key($host);
    $state = isset($all[$key]) ? array_merge(mh_default_bandwidth_state(), $all[$key]) : mh_default_bandwidth_state();

    $targetBytes = (float) $currentUsageGb * 1024 * 1024 * 1024;
    $trackedBytes = $state['cycle_rx_bytes'] + $state['cycle_tx_bytes'];
    $state['sync_offset_bytes'] = $targetBytes - $trackedBytes;

    $all[$key] = $state;
    mh_write_json('bandwidth.json', $all);

    return $state;
}

/**
 * ---------- KUOTA USER STATIC (dari Simple Queue per IP) ----------
 * Pola sama persis dengan mh_track_usage_categories(): bandingkan counter
 * mentah tiap poll, akumulasikan selisihnya. Counter turun (Queue di-reset /
 * router reboot) dianggap mulai dari 0 lagi, BUKAN dikurangi - supaya kuota
 * yang sudah terpakai tidak pernah "hilang" gara-gara restart.
 */
function mh_default_static_quota_state()
{
    return array(
        'cycle_start' => '',
        'last_raw'    => array(),  // ip => counter mentah terakhir dari Queue
        'cycle_bytes' => array(),  // ip => akumulasi terpakai siklus berjalan
        'last_update' => '',
    );
}

/**
 * $rawBytesByIp: array('10.20.30.35' => 15234000000, ...) - counter MENTAH
 * (rx+tx) dari Simple Queue saat ini, hasil mh_fetch_static_queue_bytes().
 */
function mh_track_static_quota($host, $rawBytesByIp, $resetDay)
{
    $all = mh_read_json('static_quota.json', array());
    $key = mh_settings_key($host);
    $state = isset($all[$key]) ? array_merge(mh_default_static_quota_state(), $all[$key]) : mh_default_static_quota_state();

    $cycleStart = mh_cycle_start($resetDay);
    if ($state['cycle_start'] !== $cycleStart) {
        $state['cycle_start'] = $cycleStart;
        $state['cycle_bytes'] = array();
        // last_raw TIDAK direset - tetap dipakai sebagai basis delta siklus baru.
    }

    if (is_array($rawBytesByIp)) {
        foreach ($rawBytesByIp as $ip => $val) {
            $val = (float) $val;
            $last = isset($state['last_raw'][$ip]) ? $state['last_raw'][$ip] : null;
            $prevCycle = isset($state['cycle_bytes'][$ip]) ? $state['cycle_bytes'][$ip] : 0;
            if ($last !== null) {
                $delta = $val >= $last ? $val - $last : $val; // counter turun = queue di-reset/reboot
                $prevCycle += max(0, $delta);
            }
            $state['cycle_bytes'][$ip] = $prevCycle;
            $state['last_raw'][$ip] = $val;
        }
        $state['last_update'] = date('Y-m-d H:i:s');
    }

    $all[$key] = $state;
    mh_write_json('static_quota.json', $all);

    return $state;
}

/**
 * ---------- UPTIME REAL USER STATIC (dari hasil ping) ----------
 * Status online/offline sendiri sudah dites lewat ping di mh_fetch_ip_bindings().
 * Fungsi ini HANYA mencatat "sejak kapan" tiap IP terakhir kali mulai online
 * beruntun, supaya durasinya bisa dihitung tiap poll tanpa perlu router
 * menyimpan apa-apa. Disimpan di file terpisah (bukan di memori PHP) supaya
 * kalau server restart, durasi yang sedang berjalan tidak ikut ke-reset ke 0.
 */
function mh_default_static_uptime_state()
{
    return array('devices' => array()); // ip => array('online'=>bool, 'since'=>timestamp|null)
}

/** $onlineMap: array('10.20.30.35' => true, '10.20.30.40' => false, ...) hasil ping poll saat ini. */
function mh_track_static_uptime($host, $onlineMap)
{
    $all = mh_read_json('static_uptime.json', array());
    $key = mh_settings_key($host);
    $state = isset($all[$key]) ? array_merge(mh_default_static_uptime_state(), $all[$key]) : mh_default_static_uptime_state();

    $now = time();
    foreach ($onlineMap as $ip => $isOnline) {
        $dev = isset($state['devices'][$ip])
            ? $state['devices'][$ip]
            : array('online' => false, 'since' => null, 'last_offline_at' => null);

        if ($isOnline) {
            if (empty($dev['online']) || $dev['since'] === null) {
                $dev['since'] = $now; // baru saja mulai online beruntun
            }
            $dev['online'] = true;
        } else {
            if (!empty($dev['online'])) {
                $dev['last_offline_at'] = $now; // baru saja terputus - catat waktunya
            }
            $dev['online'] = false;
            $dev['since'] = null;
        }
        $state['devices'][$ip] = $dev;
    }

    $all[$key] = $state;
    mh_write_json('static_uptime.json', $all);

    return $state;
}
