<?php
// Poll CEPAT (default 15 detik): hanya data ringan - online, CPU/uptime, bandwidth.
// Data penjualan (berat) ada di api/sales.php dan dipanggil lebih jarang.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!mh_logged_in()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'not_logged_in'));
    exit;
}

$api = mh_connect_from_session();
if (!$api) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'Tidak bisa terhubung ke router.'));
    exit;
}

$light = mh_build_light($api, $_SESSION['mh_host']);
$api->disconnect();

echo json_encode(array(
    'ok'      => true,
    'summary' => $light,
));
