<?php
// Poll LEBIH JARANG (default 60 detik): data penjualan - hari ini/bulan ini/7 hari/
// transaksi terbaru/grafik bulanan. Dipisah dari api/live.php supaya query yang lebih
// berat (baca semua data voucher) tidak dijalankan setiap 15 detik.
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

$sales = mh_build_sales($api, $_SESSION['mh_host']);
$api->disconnect();

echo json_encode(array(
    'ok'      => true,
    'summary' => $sales,
));
