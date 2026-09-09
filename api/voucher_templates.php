<?php
// Endpoint khusus data cetak voucher (profil hotspot/logo + template custom).
// SENGAJA TIDAK connect ke router sama sekali - ini murni baca/tulis file
// pengaturan lokal, supaya panel Cetak selalu cepat dan tidak ikut gagal
// kalau router sedang lambat/putus.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/storage.php';

header('Content-Type: application/json; charset=utf-8');

if (!mh_logged_in()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'not_logged_in'));
    exit;
}

$host = $_SESSION['mh_host'];
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'profile';

$writeActions = array('save_template', 'delete_template');
if (in_array($action, $writeActions, true)) {
    if (!mh_csrf_check(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'CSRF tidak valid, muat ulang halaman lalu coba lagi.'));
        exit;
    }
}

switch ($action) {
    case 'profile':
        $s = mh_get_settings($host);
        $logoUrl = '';
        if ($s['voucher_logo'] !== '' && file_exists(__DIR__ . '/../assets/uploads/' . $s['voucher_logo'])) {
            $logoUrl = 'assets/uploads/' . $s['voucher_logo'];
        }
        echo json_encode(array(
            'ok'            => true,
            'hotspot_name'  => $s['voucher_hotspot_name'],
            'login_address' => $s['voucher_login_address'],
            'footer_note'   => $s['voucher_footer_note'],
            'logo_url'      => $logoUrl,
        ));
        break;

    case 'list_templates':
        echo json_encode(array('ok' => true, 'templates' => mh_get_voucher_templates($host)));
        break;

    case 'save_template':
        $name = trim((string) (isset($_POST['name']) ? $_POST['name'] : ''));
        $html = (string) (isset($_POST['html']) ? $_POST['html'] : '');
        if ($name === '') {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'Nama template wajib diisi.'));
            break;
        }
        $ok = mh_save_voucher_template($host, $name, $html);
        echo json_encode(array('ok' => (bool) $ok, 'name' => $name));
        break;

    case 'delete_template':
        $name = trim((string) (isset($_POST['name']) ? $_POST['name'] : ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'Nama template wajib diisi.'));
            break;
        }
        $ok = mh_delete_voucher_template($host, $name);
        echo json_encode(array('ok' => (bool) $ok));
        break;

    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'unknown_action'));
}
