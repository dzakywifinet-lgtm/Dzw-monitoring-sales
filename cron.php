<?php
/**
 * MODE CRON
 * ---------
 * Endpoint ini dipanggil dari crontab server (bukan dari browser), supaya
 * pencatatan bandwidth/kategori pemakaian dan notifikasi Telegram voucher baru
 * tetap berjalan walau dashboard tidak pernah dibuka.
 *
 * Diamankan dengan TOKEN RAHASIA (query string ?token=...), bukan sesi login -
 * karena crontab tidak bisa "login" seperti browser. Kredensial router yang
 * dipakai di sini dibaca dari data/cron_creds.json (terenkripsi AES-256-CBC,
 * kunci di data/.cronkey), diisi otomatis saat Mode Cron diaktifkan lewat
 * halaman Pengaturan.
 *
 * Aktifkan/nonaktifkan & lihat perintah cron siap-pakai di: Pengaturan >
 * "Mode Cron (Pengecekan Latar Belakang)".
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/storage.php';

header('Content-Type: application/json; charset=utf-8');

// Token bisa dikirim lewat query string (curl/wget) atau argumen CLI (php cron.php TOKEN).
$token = '';
if (isset($_GET['token'])) {
    $token = (string) $_GET['token'];
} elseif (php_sapi_name() === 'cli' && isset($argv[1])) {
    $token = (string) $argv[1];
}

if ($token === '') {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Token diperlukan.'));
    exit;
}

try {
    // Cari host mana yang cocok dengan token ini (bandingkan dengan hash_equals
    // supaya tahan terhadap timing attack).
    $allSettings = mh_read_json('settings.json', array());
    $matchHostKey = null;
    foreach ($allSettings as $hostKey => $s) {
        if (!empty($s['cron_enabled']) && !empty($s['cron_token']) && hash_equals((string) $s['cron_token'], $token)) {
            $matchHostKey = $hostKey;
            break;
        }
    }

    if ($matchHostKey === null) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Token tidak valid atau Mode Cron belum diaktifkan.'));
        exit;
    }

    $creds = mh_get_cron_creds($matchHostKey);
    if (!$creds || empty($creds['host']) || empty($creds['user'])) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => 'Kredensial cron tidak ditemukan atau rusak. Nonaktifkan lalu aktifkan ulang Mode Cron dari Pengaturan.'));
        exit;
    }

    $api = mh_connect(
        $creds['host'],
        $creds['user'],
        $creds['pass'],
        isset($creds['port']) ? (int) $creds['port'] : 8728,
        !empty($creds['ssl'])
    );

    if (!$api) {
        http_response_code(502);
        echo json_encode(array('ok' => false, 'error' => 'Tidak bisa terhubung/login ke router.'));
        exit;
    }

    // Tidak ada sesi browser di mode ini, jadi timezone router dibaca langsung sekali di sini.
    $tzRows = @$api->comm('/system/clock/print');
    if (!empty($tzRows[0]['time-zone-name']) && $tzRows[0]['time-zone-name'] !== 'manual') {
        @date_default_timezone_set($tzRows[0]['time-zone-name']);
    }

    // Pengecekan yang sama seperti poll dashboard: bandwidth/kuota (light) +
    // penjualan/kategori pemakaian + cek & kirim notifikasi Telegram voucher baru (sales).
    // detectLogins=false: cron.php tidak menotifikasi popup di browser (tidak punya
    // browser), jadi sengaja TIDAK ikut mengonsumsi state deteksi login di
    // data/active_sessions.json - biar jatah notifikasi popup+suara "cring" login
    // sepenuhnya milik dashboard yang sedang dibuka di browser (lihat komentar di
    // mh_build_light).
    $light = mh_build_light($api, $creds['host'], false);
    $sales = mh_build_sales($api, $creds['host']);
    $api->disconnect();

    echo json_encode(array(
        'ok'           => true,
        'checked_at'   => date('Y-m-d H:i:s'),
        'online_count' => $light['online_count'],
        'today_count'  => $sales['today_count'],
        'month_count'  => $sales['month_count'],
    ));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Kesalahan internal saat menjalankan pengecekan.'));
}
