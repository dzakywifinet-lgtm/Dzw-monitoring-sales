<?php
/**
 * Fungsi inti dashboard penghasilan Mikhmon.
 * Voucher terjual dibaca dari /system/script (format Mikhmon), koneksi bandwidth
 * dibaca dari counter interface WAN yang ditentukan di Pengaturan.
 *
 * CATATAN PERFORMA: panggilan API ke MikroTik dipecah jadi dua kelompok supaya
 * CPU router tidak dipaksa kerja berat tiap 15 detik:
 *  - "light"  (mh_build_light)  : dipanggil tiap poll cepat - hanya query ringan
 *                                  (jumlah online, resource, traffic interface).
 *  - "sales"  (mh_build_sales)  : dipanggil lebih jarang - ambil semua data voucher
 *                                  sekali lalu diolah di PHP, dengan cache singkat
 *                                  supaya beberapa tab/pengguna tidak saling menumpuk
 *                                  permintaan ke router dalam jendela waktu yang sama.
 */

require_once __DIR__ . '/../lib/routeros_api.class.php';

$MH_MONTHS = array(1 => 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec');
$MH_MONTHS_ID = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');

/** Buka koneksi ke router. Mengembalikan objek RouterosAPI atau null bila gagal. */
function mh_connect($host, $user, $pass, $port = 8728, $ssl = false)
{
    $host = trim((string) $host);
    if ($host === '') {
        return null;
    }
    $api = new RouterosAPI();
    $api->debug = false;
    $api->ssl = (bool) $ssl;
    $api->port = $port ? (int) $port : ($ssl ? 8729 : 8728);
    $api->timeout = 5;
    $api->attempts = 1;

    if (@$api->connect($host, $user, $pass)) {
        return $api;
    }
    return null;
}

/**
 * Tes cepat apakah host:port bisa dijangkau lewat jaringan (sebelum coba login API).
 * Memberi pesan error yang lebih jelas: IP tidak nyambung vs IP nyambung tapi salah login.
 */
function mh_test_reachable($host, $port, $timeout = 3)
{
    $host = trim((string) $host);
    if ($host === '') {
        return false;
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, (int) $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

/**
 * Timezone router dibaca SEKALI saat login lalu disimpan di sesi (lihat login.php),
 * supaya poll berikutnya tidak perlu memanggil /system/clock/print ke router lagi.
 */
function mh_apply_session_timezone()
{
    if (isset($_SESSION) && !empty($_SESSION['mh_tz'])) {
        @date_default_timezone_set($_SESSION['mh_tz']);
    }
}

function mh_identity($api)
{
    $id = @$api->comm('/system/identity/print');
    return isset($id[0]['name']) ? $id[0]['name'] : 'MikroTik';
}

/** Ubah format uptime RouterOS (1w1d7h18m33s) jadi "Xd HH:MM:SS" */
function mh_format_uptime($raw)
{
    if (!preg_match_all('/(\d+)([wdhms])/', (string) $raw, $m, PREG_SET_ORDER)) {
        return $raw;
    }
    $days = 0; $sec = 0;
    foreach ($m as $p) {
        $n = (int) $p[1];
        switch ($p[2]) {
            case 'w': $days += $n * 7; break;
            case 'd': $days += $n; break;
            case 'h': $sec  += $n * 3600; break;
            case 'm': $sec  += $n * 60; break;
            case 's': $sec  += $n; break;
        }
    }
    $days += intdiv($sec, 86400); $sec %= 86400;
    $h = intdiv($sec, 3600); $sec %= 3600;
    $i = intdiv($sec, 60); $s = $sec % 60;
    return sprintf('%dd %02d:%02d:%02d', $days, $h, $i, $s);
}
/** CPU load (%) dan uptime router, ditampilkan di topbar dashboard. */
function mh_resource($api)
{
    $r = @$api->comm('/system/resource/print');
    return array(
        'cpu'    => isset($r[0]['cpu-load']) ? $r[0]['cpu-load'] : '-',
        'uptime' => isset($r[0]['uptime']) ? mh_format_uptime($r[0]['uptime']) : '-',
    );
}


function mh_date_key($ts)
{
    global $MH_MONTHS;
    return $MH_MONTHS[(int) date('n', $ts)] . '/' . date('d', $ts) . '/' . date('Y', $ts);
}

/** Ubah string tanggal format Mikhmon ("sep/04/2026") jadi timestamp, tanpa ambiguitas strtotime(). */
function mh_parse_date_ts($dateStr)
{
    global $MH_MONTHS;
    $parts = explode('/', (string) $dateStr);
    if (count($parts) !== 3) {
        return false;
    }
    $monNum = array_search(strtolower($parts[0]), $MH_MONTHS);
    if ($monNum === false) {
        return false;
    }
    return mktime(0, 0, 0, $monNum, (int) $parts[1], (int) $parts[2]);
}

/** Ambil SEMUA voucher yang pernah terjual, dalam satu kali panggilan API (tanpa cache). */
function mh_fetch_all_vouchers($api)
{
    $rows = @$api->comm('/system/script/print', array('?comment' => 'mikhmon'));
    $out = array();
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        if (empty($row['name'])) {
            continue;
        }
        $p = explode('-|-', $row['name']);
        $out[] = array(
            'date'    => isset($p[0]) ? $p[0] : '',
            'time'    => isset($p[1]) ? $p[1] : '',
            'user'    => isset($p[2]) ? $p[2] : '',
            'price'   => isset($p[3]) ? (float) $p[3] : 0,
            'profile' => isset($p[7]) ? $p[7] : '',
            'comment' => isset($p[8]) ? $p[8] : '',
        );
    }
    return $out;
}

/**
 * Sama seperti mh_fetch_all_vouchers(), tapi hasilnya disimpan sebentar di file
 * cache lokal. Selama masih dalam jendela $ttl detik, permintaan berikutnya
 * (dari tab lain, pengguna lain, atau poll berikutnya) memakai data cache ini
 * tanpa membebani router lagi - ini yang paling banyak menghemat CPU MikroTik.
 */
function mh_fetch_all_vouchers_cached($api, $host, $ttl = 55)
{
    require_once __DIR__ . '/storage.php';
    $key = mh_settings_key($host);
    $all = mh_read_json('vouchers_cache.json', array());
    if (isset($all[$key]['fetched_at']) && (time() - $all[$key]['fetched_at']) < $ttl) {
        return $all[$key]['data'];
    }
    $data = mh_fetch_all_vouchers($api);
    $all[$key] = array('fetched_at' => time(), 'data' => $data);
    mh_write_json('vouchers_cache.json', $all);
    return $data;
}

function mh_format_rp($n)
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function mh_format_bytes($bytes)
{
    $bytes = (float) $bytes;
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $bytes < 10 ? 2 : 1) . ' ' . $units[$i];
}

/** Jumlah user hotspot yang sedang online saat ini (angka saja, ringan). */
function mh_fetch_hotspot_active_count($api)
{
    $rows = @$api->comm('/ip/hotspot/active/print');
    return is_array($rows) ? count($rows) : 0;
}

/** Counter traffic (rx/tx byte) dari satu interface WAN yang ditentukan user. */
function mh_fetch_interface_traffic($api, $ifaceName)
{
    $ifaceName = trim((string) $ifaceName);
    if ($ifaceName === '') {
        return null;
    }
    $rows = @$api->comm('/interface/print', array('?name' => $ifaceName));
    if (!is_array($rows) || empty($rows[0])) {
        return null;
    }
    return array(
        'rx' => isset($rows[0]['rx-byte']) ? (float) $rows[0]['rx-byte'] : 0,
        'tx' => isset($rows[0]['tx-byte']) ? (float) $rows[0]['tx-byte'] : 0,
    );
}

/**
 * Daftar "User IP Binding" hotspot (IP > Hotspot > IP Binding) beserta status
 * online-nya dan kelompoknya (AP / USER / lainnya).
 *
 * Nama tampilan diambil dari kolom Comment tiap binding (kalau kosong, jatuh
 * ke alamat IP, lalu MAC address). Kelompok "ap"/"user" dikenali dari prefiks
 * di depan Comment, mis. "AP- Totolink Ruang Depan" atau "USER- mas imam".
 *
 * STATUS ONLINE: TIDAK memakai tabel ARP sama sekali sebagai indikator -
 * entri ARP MikroTik bisa tetap tersimpan/basi walau perangkatnya (mis. HP)
 * sudah disconnect dari WiFi, sehingga dashboard bisa salah menampilkan
 * ONLINE. Sebagai gantinya, setiap IP Binding di-ping langsung satu per
 * satu; kalau MikroTik benar-benar menerima reply ping, baru dianggap
 * ONLINE. Cocok untuk sekitar belasan IP Binding (AP + HP) - untuk jumlah
 * yang jauh lebih banyak, ping satu-satu begini akan lebih lambat.
 */
function mh_fetch_ip_bindings($api)
{
    $rows = @$api->comm('/ip/hotspot/ip-binding/print');
    $out = array();
    if (!is_array($rows)) {
        return $out;
    }

    foreach ($rows as $row) {
        if (!empty($row['disabled']) && $row['disabled'] === 'true') {
            continue;
        }
        $mac = isset($row['mac-address']) ? strtoupper(trim($row['mac-address'])) : '';
        $address = isset($row['address']) ? trim($row['address']) : '';
        $rawName = '';
        if (!empty($row['comment'])) {
            $rawName = trim($row['comment']);
        } elseif ($address !== '') {
            $rawName = $address;
        } elseif ($mac !== '') {
            $rawName = $mac;
        } else {
            continue;
        }

        $online = false;
        if ($address !== '') {
            $ping = @$api->comm('/ping', array('address' => $address, 'count' => '1'));
            if (is_array($ping)) {
                foreach ($ping as $p) {
                    // Hanya dianggap ONLINE kalau MikroTik benar-benar menerima reply.
                    if (isset($p['received']) && (int) $p['received'] > 0) {
                        $online = true;
                        break;
                    }
                }
            }
        }

        $grouped = mh_ip_binding_group($rawName);

        $out[] = array(
            'name'    => $rawName,
            'label'   => $grouped['label'],
            'group'   => $grouped['group'],
            'address' => $address,
            'mac'     => $mac,
            'online'  => $online,
        );
    }
    return $out;
}

/**
 * Kenali kelompok "ap"/"user"/"other" dari prefiks di depan nama/Comment IP
 * Binding, mis. "AP- Totolink" -> group ap, label "Totolink". "USER- mas
 * imam" -> group user, label "mas imam". Kalau tidak ada prefiks yang
 * dikenali, dianggap "other" dan labelnya nama aslinya utuh.
 */
function mh_ip_binding_group($rawName)
{
    $name = trim((string) $rawName);
    if (preg_match('/^(ap|user)[\s\-:]+(.+)$/i', $name, $m)) {
        $label = trim($m[2]);
        return array('group' => strtolower($m[1]), 'label' => $label !== '' ? $label : $name);
    }
    return array('group' => 'other', 'label' => $name);
}

/** Daftar semua interface di router, untuk dropdown pemilih di kartu traffic real-time. */
function mh_list_interfaces($api)
{
    $rows = @$api->comm('/interface/print');
    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $r) {
            if (!empty($r['name'])) {
                $out[] = array(
                    'name'    => $r['name'],
                    'running' => isset($r['running']) && $r['running'] === 'true',
                );
            }
        }
    }
    return $out;
}

/** Kecepatan traffic SAAT INI (bps) pada satu interface - untuk grafik "wave" real-time. */
function mh_fetch_interface_live($api, $ifaceName)
{
    $ifaceName = trim((string) $ifaceName);
    if ($ifaceName === '') {
        return null;
    }
    $rows = @$api->comm('/interface/monitor-traffic', array('interface' => $ifaceName, 'once' => ''));
    if (!is_array($rows) || empty($rows[0])) {
        return null;
    }
    $row = $rows[0];
    return array(
        'rx_bps' => isset($row['rx-bits-per-second']) ? (float) $row['rx-bits-per-second'] : 0,
        'tx_bps' => isset($row['tx-bits-per-second']) ? (float) $row['tx-bits-per-second'] : 0,
    );
}

/** Ringkasan penjualan (hari ini, bulan ini, 7 hari + rincian per hari, transaksi terbaru) dari data yang sudah diambil. */
function mh_build_summary_from_data($allVouchers, $now)
{
    global $MH_MONTHS_ID;

    $curOwnerMonth = (int) date('n', $now);
    $curOwnerYear = (int) date('Y', $now);

    $todayKey = mh_date_key($now);
    $todayTotal = 0;
    $todayCount = 0;
    $monthTotal = 0;
    $monthCount = 0;
    $curMonthData = array();

    foreach ($allVouchers as $row) {
        $rowTs = mh_parse_date_ts($row['date']);
        if ($rowTs === false) {
            continue;
        }
        if ((int) date('n', $rowTs) === $curOwnerMonth && (int) date('Y', $rowTs) === $curOwnerYear) {
            $monthTotal += $row['price'];
            $monthCount++;
            $curMonthData[] = $row;
        }
        if ($row['date'] === $todayKey) {
            $todayTotal += $row['price'];
            $todayCount++;
        }
    }

    // 7 hari terakhir, lengkap dengan rincian transaksi per hari
    $week = array();
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-$i day", $now);
        $key = mh_date_key($ts);
        $total = 0;
        $count = 0;
        $items = array();
        foreach ($allVouchers as $row) {
            if ($row['date'] === $key) {
                $total += $row['price'];
                $count++;
                $items[] = array(
                    'time'    => $row['time'],
                    'user'    => $row['user'],
                    'profile' => $row['profile'],
                    'price'   => $row['price'],
                );
            }
        }
        usort($items, function ($a, $b) {
            return strcmp($a['time'], $b['time']);
        });
        $week[] = array(
            'label'      => date('d/m', $ts),
            'date_label' => date('d M Y', $ts),
            'total'      => $total,
            'count'      => $count,
            'items'      => $items,
        );
    }

    $recentSort = $curMonthData;
    usort($recentSort, function ($a, $b) {
        return strcmp($b['date'] . ' ' . $b['time'], $a['date'] . ' ' . $a['time']);
    });
    // Ditampilkan di kartu "Transaksi terbaru" - hanya 5 baris kelihatan
    // sekaligus di layar (lihat CSS mh-table-scroll), sisanya tinggal di-scroll,
    // jadi batas di sini dinaikkan supaya praktis "semua transaksi bulan ini".
    $recent = array_slice($recentSort, 0, 300);

    return array(
        'today_total'  => $todayTotal,
        'today_count'  => $todayCount,
        'month_total'  => $monthTotal,
        'month_count'  => $monthCount,
        'month_label'  => $MH_MONTHS_ID[$curOwnerMonth] . ' ' . $curOwnerYear,
        'week'         => $week,
        'recent'       => $recent,
    );
}

/** Total penjualan per bulan untuk tahun berjalan, dari data yang sudah diambil (tanpa panggilan API tambahan). */
function mh_build_yearly_from_data($allVouchers, $year)
{
    global $MH_MONTHS_ID;
    $curMonth = (int) date('n');
    $curYear = (int) date('Y');

    $months = array();
    for ($m = 1; $m <= 12; $m++) {
        $months[$m] = array('label' => substr($MH_MONTHS_ID[$m], 0, 3), 'total' => 0, 'count' => 0);
    }
    foreach ($allVouchers as $row) {
        $rowTs = mh_parse_date_ts($row['date']);
        if ($rowTs === false || (int) date('Y', $rowTs) !== (int) $year) {
            continue;
        }
        $m = (int) date('n', $rowTs);
        $months[$m]['total'] += $row['price'];
        $months[$m]['count']++;
    }
    // jangan tampilkan bulan yang belum terjadi di tahun berjalan
    if ((int) $year === $curYear) {
        for ($m = $curMonth + 1; $m <= 12; $m++) {
            $months[$m]['total'] = 0;
            $months[$m]['count'] = 0;
        }
    }
    return array('year' => (int) $year, 'months' => array_values($months));
}

/** Tanggal mulai siklus FUP berjalan, berdasarkan tanggal reset yang diatur user. */
function mh_cycle_start($resetDay, $ts = null)
{
    $ts = $ts ?: time();
    $resetDay = max(1, min(28, (int) $resetDay));
    $day = (int) date('j', $ts);
    if ($day >= $resetDay) {
        $start = mktime(0, 0, 0, (int) date('n', $ts), $resetDay, (int) date('Y', $ts));
    } else {
        $prevTs = strtotime('-1 month', $ts);
        $start = mktime(0, 0, 0, (int) date('n', $prevTs), $resetDay, (int) date('Y', $prevTs));
    }
    return date('Y-m-d', $start);
}

/** Hitung angka-angka tampilan bandwidth/FUP dari state tersimpan (tanpa panggilan API). */
function mh_bandwidth_view($settings, $bw)
{
    $quotaBytes = $settings['quota_gb'] * 1024 * 1024 * 1024;
    $usedBytes = $bw['cycle_rx_bytes'] + $bw['cycle_tx_bytes'] + $bw['sync_offset_bytes'];
    $percent = $quotaBytes > 0 ? round(($usedBytes / $quotaBytes) * 100, 1) : 0;
    $remainBytes = $quotaBytes > 0 ? max(0, $quotaBytes - $usedBytes) : 0;

    $prevCycle = null;
    if (!empty($bw['history'])) {
        $keys = array_keys($bw['history']);
        $lastKey = end($keys);
        $prevCycle = $bw['history'][$lastKey];
        $prevCycle['label'] = $lastKey;
    }

    return array(
        'interface_set' => $settings['interface'] !== '',
        'interface'     => $settings['interface'],
        'quota_gb'      => $settings['quota_gb'],
        'reset_day'     => $settings['reset_day'],
        'cycle_start'   => $bw['cycle_start'],
        'used_bytes'    => $usedBytes,
        'used_fmt'      => mh_format_bytes($usedBytes),
        'rx_bytes'      => $bw['cycle_rx_bytes'],
        'rx_fmt'        => mh_format_bytes($bw['cycle_rx_bytes']),
        'tx_bytes'      => $bw['cycle_tx_bytes'],
        'tx_fmt'        => mh_format_bytes($bw['cycle_tx_bytes']),
        'quota_bytes'   => $quotaBytes,
        'quota_fmt'     => $quotaBytes > 0 ? mh_format_bytes($quotaBytes) : '-',
        'remain_bytes'  => $remainBytes,
        'remain_fmt'    => $quotaBytes > 0 ? mh_format_bytes($remainBytes) : '-',
        'percent'       => $percent,
        'last_update'   => $bw['last_update'],
        'prev_cycle'    => $prevCycle,
    );
}

/**
 * Definisi kategori "Analisis Pemakaian". HARUS SINKRON dengan comment mangle
 * rule yang dibuat lewat skrip router (lihat usage-kategori-mikrotik.rsc):
 * tiap kategori punya rule mark-packet dengan comment "usage:<key> pkt".
 */
function mh_usage_category_defs()
{
    return array(
        'video'  => array('label' => 'Video (FB/YT/TikTok/Live)', 'tag' => 'usage:video', 'color' => '#e05252'),
        'google' => array('label' => 'Layanan Google', 'tag' => 'usage:google', 'color' => '#4c8bf5'),
        'wa'     => array('label' => 'Komunikasi (WhatsApp)', 'tag' => 'usage:wa', 'color' => '#3fbf6f'),
    );
}

/**
 * Baca counter byte mentah tiap kategori dari mangle rule di router
 * (rule mark-packet, comment "usage:<key> pkt" - lihat mh_usage_category_defs).
 * Butuh keyword "stats" supaya field byte counter ikut dikirim router.
 */
function mh_fetch_usage_category_bytes($api)
{
    $defs = mh_usage_category_defs();
    $result = array();
    foreach ($defs as $key => $d) {
        $result[$key] = 0;
    }

    $rows = @$api->comm('/ip/firewall/mangle/print', array('stats' => ''));
    if (!is_array($rows)) {
        return $result;
    }
    foreach ($rows as $row) {
        $comment = isset($row['comment']) ? $row['comment'] : '';
        if ($comment === '' || !isset($row['bytes'])) {
            continue;
        }
        foreach ($defs as $key => $d) {
            if (strpos($comment, $d['tag'] . ' pkt') === 0) {
                $result[$key] += (float) $row['bytes'];
            }
        }
    }
    return $result;
}

/**
 * Bentuk tampilan kartu "Analisis Pemakaian": byte + persen tiap kategori yang
 * dikenali dari mangle rule, sisanya (browsing umum, domain lain yang belum
 * masuk daftar, dst) dikelompokkan sebagai "Web & lainnya" = total pemakaian
 * siklus (dari kartu bandwidth) dikurangi jumlah kategori yang dikenali.
 */
function mh_usage_categories_view($usageState, $totalUsedBytes)
{
    $defs = mh_usage_category_defs();
    $items = array();
    $sumKnown = 0;

    foreach ($defs as $key => $d) {
        $bytes = isset($usageState['cycle_bytes'][$key]) ? $usageState['cycle_bytes'][$key] : 0;
        $sumKnown += $bytes;
        $items[] = array(
            'key'   => $key,
            'label' => $d['label'],
            'color' => $d['color'],
            'bytes' => $bytes,
            'fmt'   => mh_format_bytes($bytes),
        );
    }

    $others = max(0, $totalUsedBytes - $sumKnown);
    $items[] = array(
        'key'   => 'other',
        'label' => 'Web & lainnya',
        'color' => '#9aa5a0',
        'bytes' => $others,
        'fmt'   => mh_format_bytes($others),
    );

    $base = max($totalUsedBytes, $sumKnown, 1);
    foreach ($items as &$it) {
        $it['percent'] = round(($it['bytes'] / $base) * 100, 1);
    }
    unset($it);

    return array(
        'items'       => $items,
        'last_update' => $usageState['last_update'],
    );
}

/**
 * Kelompok data RINGAN: online, CPU/uptime, bandwidth. Dipanggil tiap poll cepat
 * (default 15 detik) - hanya query-query murah ke router, TIDAK mengambil data voucher.
 *
 * $detectLogins: kalau true (default), turut menjalankan mh_detect_new_hotspot_logins()
 * yang MENGUBAH state tersimpan di data/active_sessions.json. SENGAJA dibuat bisa
 * dimatikan (lihat cron.php) - soalnya kalau Mode Cron aktif, cron.php JUGA memanggil
 * fungsi ini secara independen; kalau keduanya (cron & browser) sama-sama "mengonsumsi"
 * state deteksi yang sama, siapa pun yang polling duluan akan menandai login itu
 * sebagai "sudah dilihat" - dan yang satunya lagi (browser) tidak akan pernah
 * kebagian notifikasi + suara "cring" untuk login itu. cron.php TIDAK memakai hasil
 * new_logins sama sekali (lihat isinya), jadi cron.php memanggil dengan false supaya
 * tidak ikut "mencuri" jatah deteksi milik browser.
 */
function mh_build_light($api, $host, $detectLogins = true)
{
    require_once __DIR__ . '/storage.php';
    mh_apply_session_timezone();
    $now = time();

    $settings = mh_get_settings($host);
    $iface = mh_fetch_interface_traffic($api, $settings['interface']);
    $bwState = mh_track_bandwidth($host, $iface, $settings['reset_day']);

    // Daftar lengkap hotspot aktif dibaca sekali di sini (bukan cuma count-nya
    // saja) supaya bisa dibandingkan dengan poll sebelumnya - itulah yang
    // dipakai buat mendeteksi user yang BARU login (lihat
    // mh_detect_new_hotspot_logins), yang lalu memicu popup + suara "cring"
    // di dashboard tiap ada voucher/user hotspot login, TIDAK peduli
    // vouchernya dibuat lewat Generate bawaan dashboard ini atau aplikasi
    // Mikhmon terpisah.
    $activeList = mh_fetch_hotspot_active_list($api);
    $newLogins = $detectLogins ? mh_detect_new_hotspot_logins($host, $activeList) : array();

    // Identitas router & daftar interface juga dimasukkan di sini (query murah,
    // sama seperti CPU/uptime) supaya ikut tersimpan di cache dashboard dan
    // index.php tidak perlu menghubungi router sendiri hanya untuk dua hal ini.
    $light = array(
        'identity'     => mh_identity($api),
        'interfaces'   => mh_list_interfaces($api),
        'online_count' => count($activeList),
        'pppoe_active_count' => mh_fetch_pppoe_active_count($api),
        'resource'     => mh_resource($api),
        'server_time'  => date('H:i:s', $now),
        'server_date'  => date('Y-m-d', $now),
        'bandwidth'    => mh_bandwidth_view($settings, $bwState),
        'ip_bindings'  => mh_fetch_ip_bindings($api),
        'new_logins'   => $newLogins,
    );

    mh_update_dash_cache($host, $light);

    return $light;
}

/**
 * Kirim pesan Telegram lewat Bot API. Pakai cURL kalau tersedia, fallback ke
 * file_get_contents. Gagal diam-diam (tidak boleh mengganggu tampilan dashboard).
 */
function mh_telegram_send($token, $chatId, $text)
{
    $token = trim((string) $token);
    $chatId = trim((string) $chatId);
    if ($token === '' || $chatId === '') {
        return false;
    }
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $params = array('chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $res = @curl_exec($ch);
        $ok = $res !== false;
        curl_close($ch);
        return $ok;
    }

    $opts = array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($params),
        'timeout' => 6,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return $res !== false;
}

/** Tanda pengenal unik satu voucher, dipakai untuk mendeteksi mana yang belum pernah diberi tahu. */
function mh_voucher_signature($row)
{
    return $row['date'] . '|' . $row['time'] . '|' . $row['user'] . '|' . $row['price'] . '|' . $row['profile'];
}

/**
 * Bandingkan data voucher saat ini dengan daftar yang "sudah pernah dilihat".
 * Baru pertama kali dipakai -> semua voucher yang sudah ada dianggap baseline
 * (tidak memicu notifikasi), supaya tidak tiba-tiba kirim ratusan pesan untuk
 * riwayat lama. Setelah itu, hanya voucher yang benar-benar baru yang dikembalikan,
 * dan masing-masing hanya akan muncul SEKALI (tidak terulang di pemanggilan berikutnya).
 */
function mh_detect_new_vouchers($host, $allVouchers)
{
    require_once __DIR__ . '/storage.php';
    $key = mh_settings_key($host);
    $all = mh_read_json('notified.json', array());
    $state = isset($all[$key]) ? $all[$key] : array('seen' => array(), 'initialized' => false);

    $currentSigs = array();
    foreach ($allVouchers as $row) {
        $currentSigs[] = mh_voucher_signature($row);
    }

    if (empty($state['initialized'])) {
        $state['seen'] = $currentSigs;
        $state['initialized'] = true;
        $all[$key] = $state;
        mh_write_json('notified.json', $all);
        return array();
    }

    $seenSet = array_flip($state['seen']);
    $newRows = array();
    foreach ($allVouchers as $i => $row) {
        if (!isset($seenSet[$currentSigs[$i]])) {
            $newRows[] = $row;
        }
    }

    if (!empty($newRows)) {
        // Simpan hanya jejak yang masih ada sekarang (dibatasi) supaya file tidak membengkak.
        $state['seen'] = array_slice($currentSigs, -5000);
        $all[$key] = $state;
        mh_write_json('notified.json', $all);
    }

    return $newRows;
}

/** Cek voucher baru lalu kirim notifikasi Telegram (kalau diaktifkan & token/chat id terisi). */
function mh_notify_new_vouchers($host, $allVouchers, $settings)
{
    $newRows = mh_detect_new_vouchers($host, $allVouchers);
    if (empty($newRows)) {
        return;
    }
    if (empty($settings['telegram_enabled']) || $settings['telegram_token'] === '' || $settings['telegram_chat_id'] === '') {
        return;
    }
    foreach ($newRows as $row) {
        $text = "\xF0\x9F\x8E\xAB <b>Voucher baru terjual</b>\n" .
            'User: ' . htmlspecialchars($row['user'], ENT_QUOTES, 'UTF-8') . "\n" .
            'Profil: ' . htmlspecialchars($row['profile'], ENT_QUOTES, 'UTF-8') . "\n" .
            'Harga: ' . mh_format_rp($row['price']) . "\n" .
            'Waktu: ' . $row['date'] . ' ' . $row['time'];
        mh_telegram_send($settings['telegram_token'], $settings['telegram_chat_id'], $text);
    }
}

/**
 * Kelompok data PENJUALAN: hari ini/bulan ini/7 hari/transaksi/grafik bulanan.
 * Dipanggil lebih jarang (default 60 detik) dan memakai cache voucher supaya
 * tidak memindai seluruh data setiap kali. Voucher baru dicek & dikabari lewat
 * Telegram di sini juga (tanpa panggilan API tambahan ke router).
 */
function mh_build_sales($api, $host)
{
    require_once __DIR__ . '/storage.php';
    mh_apply_session_timezone();
    $now = time();

    $allVouchers = mh_fetch_all_vouchers_cached($api, $host);

    $settings = mh_get_settings($host);
    mh_notify_new_vouchers($host, $allVouchers, $settings);

    $summary = mh_build_summary_from_data($allVouchers, $now);
    $summary['yearly'] = mh_build_yearly_from_data($allVouchers, date('Y', $now));
    $summary['server_time'] = date('H:i:s', $now);

    // Analisis pemakaian per kategori - disegarkan tiap poll penjualan (60 detik),
    // tidak di poll ringan (15 detik), supaya tidak menambah beban CPU router.
    $bwState = mh_track_bandwidth($host, null, $settings['reset_day']); // baca saja state, TANPA panggilan API interface
    $bwView = mh_bandwidth_view($settings, $bwState);
    $rawUsage = mh_fetch_usage_category_bytes($api);
    $usageState = mh_track_usage_categories($host, $rawUsage, $settings['reset_day']);
    $summary['usage'] = mh_usage_categories_view($usageState, $bwView['used_bytes']);

    mh_update_dash_cache($host, $summary);

    return $summary;
}

/** Nilai default lengkap dashboard - dipakai index.php sebelum digabung dengan cache/data live. */
function mh_dash_defaults()
{
    return array(
        'today_total' => 0, 'today_count' => 0,
        'month_total' => 0, 'month_count' => 0,
        'month_label' => '', 'week' => array(), 'recent' => array(),
        'yearly' => array('year' => date('Y'), 'months' => array()),
        'online_count' => 0,
        'pppoe_active_count' => 0,
        'resource' => array('cpu' => '-', 'uptime' => '-'),
        'server_time' => date('H:i:s'), 'server_date' => date('Y-m-d'),
        'bandwidth' => array(
            'interface_set' => false, 'interface' => '', 'quota_gb' => 0, 'reset_day' => 1,
            'cycle_start' => '', 'used_bytes' => 0, 'used_fmt' => '0 B',
            'rx_bytes' => 0, 'rx_fmt' => '0 B', 'tx_bytes' => 0, 'tx_fmt' => '0 B',
            'quota_bytes' => 0, 'quota_fmt' => '-', 'remain_bytes' => 0, 'remain_fmt' => '-',
            'percent' => 0, 'last_update' => '', 'prev_cycle' => null,
        ),
        'usage'       => array('items' => array(), 'last_update' => ''),
        'ip_bindings' => array(),
        'identity'    => 'MikroTik',
        'interfaces'  => array(),
    );
}

/**
 * Gabungkan cache/data dashboard di atas nilai default, dengan merge lebih
 * dalam untuk sub-array (bandwidth/usage/resource/yearly) supaya field yang
 * belum pernah tersimpan tetap punya nilai aman.
 */
function mh_merge_dash_data($defaults, $data)
{
    $deepKeys = array('bandwidth', 'usage', 'resource', 'yearly');
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $data)) {
            continue;
        }
        if (in_array($k, $deepKeys, true) && is_array($v) && is_array($data[$k])) {
            $defaults[$k] = array_merge($v, $data[$k]);
        } else {
            $defaults[$k] = $data[$k];
        }
    }
    return $defaults;
}

/** Gabungan lengkap, dipakai SEKALI saat halaman pertama kali dibuka (bukan tiap poll). */
function mh_build_dashboard($api, $host)
{
    $sales = mh_build_sales($api, $host);
    $light = mh_build_light($api, $host);
    return array_merge($sales, $light);
}

/* =========================================================================
 * TAMBAHAN: PPPoE aktif, detail Hotspot aktif, dan Generate Voucher/PPPoE.
 * Semua fungsi di bawah ini murni fitur BARU - tidak mengubah satupun fungsi
 * di atas, supaya laporan penghasilan yang sudah berjalan normal tetap aman.
 * ========================================================================= */

/** Jumlah sesi PPPoE yang sedang aktif (angka saja, query ringan). */
function mh_fetch_pppoe_active_count($api)
{
    $rows = @$api->comm('/ppp/active/print');
    return is_array($rows) ? count($rows) : 0;
}

/**
 * Daftar sesi PPPoE yang sedang aktif. Nama tampilan diambil dari kolom
 * Comment di PPP Secret (Pengaturan > PPP > Secrets) sesuai username yang
 * sedang login - kalau Comment kosong, jatuh ke username aslinya.
 */
function mh_fetch_pppoe_active_list($api)
{
    $active = @$api->comm('/ppp/active/print');
    $out = array();
    if (!is_array($active)) {
        return $out;
    }

    $secrets = @$api->comm('/ppp/secret/print');
    $commentMap = array();
    if (is_array($secrets)) {
        foreach ($secrets as $s) {
            if (!empty($s['name'])) {
                $commentMap[$s['name']] = isset($s['comment']) ? $s['comment'] : '';
            }
        }
    }

    // Total data per sesi: beda dari hotspot yang byte-nya sudah langsung
    // ada di /ppp/active/print, sesi PPPoE server bikin interface dinamis
    // "<pppoe-USERNAME>" - byte-nya dibaca dari situ. Ambil SEKALI semua
    // interface dulu (bukan query per-user dalam loop) baru dicocokkan.
    $ifaceRows = @$api->comm('/interface/print');
    $ifaceBytes = array();
    if (is_array($ifaceRows)) {
        foreach ($ifaceRows as $if) {
            if (!empty($if['name'])) {
                $ifaceBytes[$if['name']] = (isset($if['rx-byte']) ? (float) $if['rx-byte'] : 0)
                    + (isset($if['tx-byte']) ? (float) $if['tx-byte'] : 0);
            }
        }
    }

    foreach ($active as $row) {
        $user = isset($row['name']) ? $row['name'] : (isset($row['user']) ? $row['user'] : '-');
        $comment = isset($commentMap[$user]) ? trim($commentMap[$user]) : '';
        $ifaceName = '<pppoe-' . $user . '>';

        $out[] = array(
            'user'    => $user,
            'name'    => $comment !== '' ? $comment : $user,
            'address' => isset($row['address']) ? $row['address'] : '-',
            'uptime'  => isset($row['uptime']) ? mh_format_uptime($row['uptime']) : '-',
            'service' => isset($row['service']) ? $row['service'] : 'pppoe',
            'total'   => isset($ifaceBytes[$ifaceName]) ? mh_format_bytes($ifaceBytes[$ifaceName]) : '-',
        );
    }
    return $out;
}

/**
 * Daftar user Hotspot yang sedang online, lengkap dengan uptime, sisa waktu
 * sesi (session time left), dan total data yang sudah dipakai sesi ini.
 */
function mh_fetch_hotspot_active_list($api)
{
    $rows = @$api->comm('/ip/hotspot/active/print');
    $out = array();
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        $bytesIn = isset($row['bytes-in']) ? (float) $row['bytes-in'] : 0;
        $bytesOut = isset($row['bytes-out']) ? (float) $row['bytes-out'] : 0;
        $out[] = array(
            // '.id' MikroTik untuk sesi aktif ini - GANTI setiap kali user
            // login ulang (reconnect), jadi cocok dipakai sebagai tanda
            // pengenal "sesi baru" oleh mh_detect_new_hotspot_logins().
            // Tidak dipakai tabel Hotspot Aktif di modal, murni internal.
            'id'        => isset($row['.id']) ? $row['.id'] : '',
            'user'      => isset($row['user']) ? $row['user'] : '-',
            'address'   => isset($row['address']) ? $row['address'] : '-',
            'mac'       => isset($row['mac-address']) ? $row['mac-address'] : '-',
            'uptime'    => isset($row['uptime']) ? mh_format_uptime($row['uptime']) : '-',
            'remaining' => isset($row['session-time-left']) ? mh_format_uptime($row['session-time-left']) : '-',
            'download'  => mh_format_bytes($bytesOut),
            'upload'    => mh_format_bytes($bytesIn),
            'total'     => mh_format_bytes($bytesIn + $bytesOut),
        );
    }
    return $out;
}

/**
 * Bandingkan daftar sesi Hotspot AKTIF sekarang dengan hasil poll
 * sebelumnya, untuk mendeteksi user yang BARU SAJA login - dipakai
 * popup + suara "cring" di dashboard (lihat assets/app.js: tickLight()).
 *
 * SENGAJA TERPISAH TOTAL dari mh_detect_new_vouchers()/mh_notify_new_vouchers()
 * (yang membaca /system/script komentar "mikhmon" dari aplikasi Mikhmon
 * terpisah): itu hanya mendeteksi TRANSAKSI PENJUALAN yang dicatat aplikasi
 * Mikhmon, sehingga voucher yang dibuat lewat fitur "Generate Hotspot &
 * PPPoE" bawaan dashboard ini (mh_generate_hotspot_vouchers - yang memang
 * sengaja tidak menyentuh /system/script) TIDAK PERNAH memicu notifikasi
 * lewat jalur itu. Fungsi ini membaca LANGSUNG dari /ip/hotspot/active,
 * jadi notifikasi muncul untuk SEMUA user yang login - baik vouchernya
 * dibuat lewat Generate bawaan dashboard ini MAUPUN lewat aplikasi Mikhmon
 * terpisah.
 *
 * Poll pertama kali (belum ada state tersimpan) dianggap baseline saja -
 * tidak memicu notifikasi, supaya user yang memang sudah online dari
 * sebelumnya tidak tiba-tiba dianggap "baru login" begitu dashboard dibuka.
 */
function mh_detect_new_hotspot_logins($host, $activeList)
{
    require_once __DIR__ . '/storage.php';
    $key = mh_settings_key($host);
    $all = mh_read_json('active_sessions.json', array());
    $state = isset($all[$key]) ? $all[$key] : array('ids' => array(), 'initialized' => false);

    $currentIds = array();
    foreach ($activeList as $row) {
        $sig = (isset($row['id']) && $row['id'] !== '') ? $row['id'] : ($row['user'] . '@' . $row['mac'] . '@' . $row['address']);
        $currentIds[] = $sig;
    }

    if (empty($state['initialized'])) {
        $all[$key] = array('ids' => $currentIds, 'initialized' => true);
        mh_write_json('active_sessions.json', $all);
        return array();
    }

    $prevSet = array_flip($state['ids']);
    $newRows = array();
    foreach ($activeList as $i => $row) {
        if (!isset($prevSet[$currentIds[$i]])) {
            $newRows[] = $row;
        }
    }

    $all[$key] = array('ids' => $currentIds, 'initialized' => true);
    mh_write_json('active_sessions.json', $all);

    return $newRows;
}

/** Kumpulan karakter untuk kode voucher, gaya Mikhmon ("Random abcd2345" dst). */
function mh_voucher_charset($key)
{
    $sets = array(
        'mixed_safe'    => 'abcdefghjkmnpqrstuvwxyz23456789',
        'lower'         => 'abcdefghijklmnopqrstuvwxyz',
        'upper'         => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'numeric'       => '0123456789',
        'lower_numeric' => 'abcdefghijklmnopqrstuvwxyz0123456789',
        'upper_numeric' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
    );
    return isset($sets[$key]) ? $sets[$key] : $sets['mixed_safe'];
}

function mh_random_voucher_code($length, $charsetKey)
{
    $chars = mh_voucher_charset($charsetKey);
    $code = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    return $code;
}

/**
 * Bangun sepasang username/password persis pola Mikhmon (generateuser.php),
 * TANPA mengubah field form Generate yang sudah ada di dashboard ini:
 *
 * - Mode "different" (Username & Password Beda = mode "up" di Mikhmon):
 *   username diacak dari charset pilihan, password SELALU ANGKA sepanjang
 *   Panjang Nama yang sama - persis kelakuan Mikhmon (password tidak pernah
 *   ikut charset username di mode ini).
 * - Mode "same" (Username = Password = kode voucher tunggal, mode "vc" di
 *   Mikhmon):
 *     - charset huruf murni ("lower"/"upper") -> kode = bagian huruf acak +
 *       akhiran angka, dengan pembagian panjang PERSIS rumus Mikhmon
 *       ($a[userl] pada generateuser.php): jumlah digit angka = floor(n/2),
 *       sisanya huruf. Hasilnya mirip "abc123", bukan huruf polos semua.
 *     - charset campuran/angka (mixed_safe, lower_numeric, upper_numeric,
 *       numeric) -> kode acak penuh sepanjang Panjang Nama dari charset itu
 *       saja, persis kelakuan Mikhmon untuk pilihan char mix/mix1/mix2/num
 *       (tidak dipecah huruf+angka).
 */
function mh_build_mikhmon_voucher_pair($prefix, $nameLength, $charsetKey, $mode)
{
    $pureLetterSets = array('lower', 'upper');

    if ($mode === 'different') {
        $username = $prefix . mh_random_voucher_code($nameLength, $charsetKey);
        $password = mh_random_voucher_code($nameLength, 'numeric');
        return array($username, $password);
    }

    if (in_array($charsetKey, $pureLetterSets, true)) {
        $digitsLen = (int) floor($nameLength / 2);
        $lettersLen = max(1, $nameLength - $digitsLen);
        $code = mh_random_voucher_code($lettersLen, $charsetKey) . mh_random_voucher_code($digitsLen, 'numeric');
    } else {
        $code = mh_random_voucher_code($nameLength, $charsetKey);
    }
    $code = $prefix . $code;
    return array($code, $code);
}

/** Ubah nilai + satuan (MB/GB) jadi jumlah byte untuk limit-bytes-total. */
function mh_data_limit_to_bytes($value, $unit)
{
    $value = (float) $value;
    if ($value <= 0) {
        return null;
    }
    $multiplier = ($unit === 'GB') ? (1024 * 1024 * 1024) : (1024 * 1024);
    return (int) round($value * $multiplier);
}

/** Daftar nama profile Hotspot (dropdown Generate & kartu ringkasan Sisa Voucher). */
function mh_fetch_hotspot_profiles($api)
{
    $rows = @$api->comm('/ip/hotspot/user/profile/print');
    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $r) {
            if (!empty($r['name'])) { $out[] = $r['name']; }
        }
    }
    return $out;
}

/** Daftar Hotspot server yang ada di router (dropdown Server saat Generate). */
function mh_fetch_hotspot_servers($api)
{
    $rows = @$api->comm('/ip/hotspot/print');
    $out = array('all');
    if (is_array($rows)) {
        foreach ($rows as $r) {
            if (!empty($r['name'])) { $out[] = $r['name']; }
        }
    }
    return $out;
}

/** Daftar nama profile PPPoE (dropdown saat Buat Akun PPPoE). */
function mh_fetch_ppp_profiles($api)
{
    $rows = @$api->comm('/ppp/profile/print');
    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $r) {
            if (!empty($r['name'])) { $out[] = $r['name']; }
        }
    }
    return $out;
}

/**
 * Ringkasan voucher Hotspot per profile: total dibuat, sudah kepakai
 * (uptime bukan 0s), dan SISA yang belum pernah dipakai sama sekali -
 * inilah yang ditampilkan di kartu "Sisa Voucher" (mis. 5-JAM, 7-JAM,
 * BONUS_2000), otomatis mengikuti profile apa saja yang ada di router.
 */
function mh_fetch_hotspot_voucher_summary($api)
{
    $rows = @$api->comm('/ip/hotspot/user/print');
    $summary = array();
    if (!is_array($rows)) {
        return array_values($summary);
    }
    foreach ($rows as $row) {
        if (empty($row['name'])) { continue; }
        $profile = (isset($row['profile']) && $row['profile'] !== '') ? $row['profile'] : '(tanpa profile)';
        if (!isset($summary[$profile])) {
            $summary[$profile] = array('profile' => $profile, 'total' => 0, 'used' => 0, 'unused' => 0);
        }
        $summary[$profile]['total']++;
        $used = isset($row['uptime']) && $row['uptime'] !== '0s';
        if ($used) {
            $summary[$profile]['used']++;
        } else {
            $summary[$profile]['unused']++;
        }
    }
    $out = array_values($summary);
    usort($out, function ($a, $b) { return strcasecmp($a['profile'], $b['profile']); });
    return $out;
}

function mh_mt_result_has_error($result)
{
    return is_array($result) && isset($result[0]['message']);
}
function mh_mt_result_error_text($result)
{
    return isset($result[0]['message']) ? $result[0]['message'] : 'Perintah ke router gagal.';
}

/**
 * Generate voucher Hotspot sekaligus banyak (gaya Mikhmon) - langsung
 * membuat akun di /ip/hotspot/user.
 *
 * PENTING - SENGAJA TIDAK MENYENTUH /system/script SAMA SEKALI:
 * Laporan Penghasilan (mh_fetch_all_vouchers) HANYA membaca
 * /system/script?comment=mikhmon (dibuat oleh aplikasi Mikhmon Anda yang
 * terpisah). Fungsi ini murni menulis ke /ip/hotspot/user, jadi voucher
 * yang dibuat dari fitur Generate ini TIDAK PERNAH ikut terhitung sebagai
 * "transaksi/penjualan" di Hari ini / Bulan ini / Transaksi terbaru -
 * keduanya sengaja dipisah total supaya laporan penghasilan yang sudah
 * berjalan normal tidak ikut berubah. JANGAN tambahkan panggilan ke
 * /system/script di fungsi ini.
 */
function mh_generate_hotspot_vouchers($api, $opt)
{
    $count = max(1, min(500, (int) (isset($opt['count']) ? $opt['count'] : 1)));
    $server = trim((string) (isset($opt['server']) ? $opt['server'] : 'all'));
    if ($server === '') { $server = 'all'; }
    $mode = (isset($opt['mode']) && $opt['mode'] === 'different') ? 'different' : 'same';
    $nameLength = max(3, min(12, (int) (isset($opt['name_length']) ? $opt['name_length'] : 6)));
    $prefix = trim((string) (isset($opt['prefix']) ? $opt['prefix'] : ''));
    $charset = (string) (isset($opt['charset']) ? $opt['charset'] : 'mixed_safe');
    $profile = trim((string) (isset($opt['profile']) ? $opt['profile'] : ''));
    $timeLimit = trim((string) (isset($opt['time_limit']) ? $opt['time_limit'] : ''));
    $dataLimitVal = trim((string) (isset($opt['data_limit']) ? $opt['data_limit'] : ''));
    $dataLimitUnit = (isset($opt['data_limit_unit']) && $opt['data_limit_unit'] === 'GB') ? 'GB' : 'MB';
    $comment = trim((string) (isset($opt['comment']) ? $opt['comment'] : ''));

    if ($profile === '') {
        return array('created' => array(), 'failed' => array('Profile wajib dipilih.'), 'connection_lost' => false);
    }
    if ($comment === '') {
        // Prefix "Stok" sengaja dipakai supaya di daftar Hotspot User router
        // langsung kelihatan jelas ini voucher stok baru, bukan hasil transaksi.
        $comment = 'Stok Generate ' . date('d/m/Y H:i:s');
    }

    $created = array();
    $failed = array();
    $connectionLost = false;

    for ($i = 0; $i < $count && !$connectionLost; $i++) {
        $ok = false;
        $lastError = '';
        for ($attempt = 0; $attempt < 3 && !$ok; $attempt++) {
            list($username, $password) = mh_build_mikhmon_voucher_pair($prefix, $nameLength, $charset, $mode);

            $params = array('name' => $username, 'password' => $password, 'profile' => $profile);
            if ($server !== 'all') { $params['server'] = $server; }
            if ($timeLimit !== '') { $params['limit-uptime'] = $timeLimit; }
            $bytesLimit = mh_data_limit_to_bytes($dataLimitVal, $dataLimitUnit);
            if ($bytesLimit !== null) { $params['limit-bytes-total'] = (string) $bytesLimit; }
            if ($comment !== '') { $params['comment'] = $comment; }

            $result = @$api->comm('/ip/hotspot/user/add', $params);

            if (property_exists($api, 'lastReadTimedOut') && $api->lastReadTimedOut) {
                $connectionLost = true;
                $lastError = 'Koneksi ke router terputus di tengah proses.';
                break;
            }
            if (mh_mt_result_has_error($result)) {
                $lastError = mh_mt_result_error_text($result);
                continue;
            }

            $created[] = array(
                'name'       => $username,
                'password'   => $password,
                'profile'    => $profile,
                'time_limit' => $timeLimit,
                'data_limit' => $bytesLimit ? ($dataLimitVal . ' ' . $dataLimitUnit) : '',
                'comment'    => $comment,
            );
            $ok = true;
        }
        if (!$ok && !$connectionLost) {
            $failed[] = $lastError !== '' ? $lastError : 'Gagal membuat voucher.';
        }
    }

    return array('created' => $created, 'failed' => $failed, 'connection_lost' => $connectionLost);
}

/** Buat satu akun PPPoE (PPP Secret) baru. */
function mh_create_pppoe_secret($api, $opt)
{
    $username = trim((string) (isset($opt['username']) ? $opt['username'] : ''));
    $password = trim((string) (isset($opt['password']) ? $opt['password'] : ''));
    $profile = trim((string) (isset($opt['profile']) ? $opt['profile'] : ''));
    $comment = trim((string) (isset($opt['comment']) ? $opt['comment'] : ''));
    $localAddress = trim((string) (isset($opt['local_address']) ? $opt['local_address'] : ''));
    $remoteAddress = trim((string) (isset($opt['remote_address']) ? $opt['remote_address'] : ''));

    if ($username === '' || $password === '' || $profile === '') {
        return array('ok' => false, 'error' => 'Username, password, dan profile wajib diisi.');
    }

    $params = array('name' => $username, 'password' => $password, 'service' => 'pppoe', 'profile' => $profile);
    if ($comment !== '') { $params['comment'] = $comment; }
    if ($localAddress !== '') { $params['local-address'] = $localAddress; }
    if ($remoteAddress !== '') { $params['remote-address'] = $remoteAddress; }

    $result = @$api->comm('/ppp/secret/add', $params);
    if (mh_mt_result_has_error($result)) {
        return array('ok' => false, 'error' => mh_mt_result_error_text($result));
    }
    return array('ok' => true, 'username' => $username, 'password' => $password, 'profile' => $profile, 'comment' => $comment);
}

/**
 * Daftar SEMUA voucher Hotspot pada satu profile tertentu - dipakai saat
 * kartu profile di "Sisa Voucher" diklik (lihat isi stok, hapus per
 * voucher berdasar Comment/username, atau cetak). Yang BELUM terpakai
 * ditaruh di atas supaya gampang dipilih buat dicetak/dijual.
 */
function mh_fetch_hotspot_users_by_profile($api, $profile)
{
    $rows = @$api->comm('/ip/hotspot/user/print', array('?profile' => $profile));
    $out = array();
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        if (empty($row['name'])) {
            continue;
        }
        $used = isset($row['uptime']) && $row['uptime'] !== '0s';
        $out[] = array(
            'name'         => $row['name'],
            'password'     => isset($row['password']) ? $row['password'] : '',
            'comment'      => isset($row['comment']) ? $row['comment'] : '',
            'used'         => $used,
            'uptime'       => isset($row['uptime']) ? mh_format_uptime($row['uptime']) : '-',
            'limit_uptime' => isset($row['limit-uptime']) ? $row['limit-uptime'] : '',
            'limit_bytes'  => isset($row['limit-bytes-total']) ? mh_format_bytes((float) $row['limit-bytes-total']) : '',
        );
    }
    usort($out, function ($a, $b) {
        if ($a['used'] === $b['used']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $a['used'] ? 1 : -1; // yang belum terpakai ditaruh duluan
    });
    return $out;
}

/**
 * Ambil info Harga/Masa Aktif/Kunci User dari SATU profile Hotspot, dibaca
 * dari kolom "on-login" persis format yang dipakai Mikhmon (nilai di
 * dalamnya dipisah koma: index 2 = harga modal, 3 = masa aktif/validity,
 * 4 = harga jual, 6 = lock user). Dipakai HANYA untuk tampilan cetak voucher
 * (bukan untuk laporan penjualan), supaya nota cetak dari fitur Generate
 * dashboard ini menampilkan Harga/Masa Aktif/Kunci User persis seperti nota
 * Mikhmon - walau voucher-nya dibuat lewat fitur Generate ini (bukan lewat
 * aplikasi Mikhmon terpisah).
 */
function mh_fetch_hotspot_profile_pricing($api, $profile)
{
    $out = array('validity' => '', 'price' => 0, 'selling_price' => 0, 'lock_user' => '');
    $profile = trim((string) $profile);
    if ($profile === '') {
        return $out;
    }
    $rows = @$api->comm('/ip/hotspot/user/profile/print', array('?name' => $profile));
    if (!is_array($rows) || empty($rows[0]['on-login'])) {
        return $out;
    }
    $parts = explode(',', (string) $rows[0]['on-login']);
    $out['price'] = isset($parts[2]) ? (float) $parts[2] : 0;
    $out['validity'] = isset($parts[3]) ? $parts[3] : '';
    $out['selling_price'] = isset($parts[4]) ? (float) $parts[4] : 0;
    $out['lock_user'] = isset($parts[6]) ? $parts[6] : '';
    return $out;
}

/** Hapus satu voucher/akun Hotspot dari router berdasar username. */
function mh_delete_hotspot_user($api, $username)
{
    $username = trim((string) $username);
    if ($username === '') {
        return array('ok' => false, 'error' => 'Username tidak boleh kosong.');
    }
    $rows = @$api->comm('/ip/hotspot/user/print', array('?name' => $username));
    if (!is_array($rows) || empty($rows[0]['.id'])) {
        return array('ok' => false, 'error' => 'Voucher tidak ditemukan (mungkin sudah dihapus sebelumnya).');
    }
    $result = @$api->comm('/ip/hotspot/user/remove', array('.id' => $rows[0]['.id']));
    if (mh_mt_result_has_error($result)) {
        return array('ok' => false, 'error' => mh_mt_result_error_text($result));
    }
    return array('ok' => true, 'name' => $username);
}

/**
 * Hapus BANYAK voucher Hotspot sekaligus berdasar daftar username - dipakai
 * tombol "Hapus Comment" (hapus semua voucher yang sedang tampil di tabel,
 * hasil filter search + comment, sesuai batch generate tertentu).
 */
function mh_delete_hotspot_users_bulk($api, $usernames)
{
    $deleted = array();
    $failed = array();
    foreach ($usernames as $username) {
        $result = mh_delete_hotspot_user($api, $username);
        if (!empty($result['ok'])) {
            $deleted[] = $username;
        } else {
            $failed[] = $username . ' (' . $result['error'] . ')';
        }
    }
    return array('deleted' => $deleted, 'failed' => $failed);
}
