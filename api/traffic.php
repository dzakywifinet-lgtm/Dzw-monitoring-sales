<?php
// Endpoint KHUSUS untuk kartu "Traffic real-time" (grafik wave) - dipoll lebih
// sering (beberapa detik) daripada live.php (15 detik), makanya dipisah jadi
// file sendiri: cuma satu panggilan ringan (/interface/monitor-traffic once),
// tidak ikut membawa data bandwidth/penjualan yang lebih berat.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!mh_logged_in()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'not_logged_in'));
    exit;
}

$iface = isset($_GET['iface']) ? trim((string) $_GET['iface']) : '';
if ($iface === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'iface_required'));
    exit;
}

$api = mh_connect_from_session();
if (!$api) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'Tidak bisa terhubung ke router.'));
    exit;
}

$live = mh_fetch_interface_live($api, $iface);
$api->disconnect();

if ($live === null) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'Interface tidak ditemukan.'));
    exit;
}

echo json_encode(array(
    'ok'    => true,
    'iface' => $iface,
    'rx_bps' => $live['rx_bps'],
    'tx_bps' => $live['tx_bps'],
    'time'  => date('H:i:s'),
));
