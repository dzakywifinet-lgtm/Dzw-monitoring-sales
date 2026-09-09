<?php
// Endpoint BARU khusus PPPoE - dipanggil hanya saat dibutuhkan (buka modal
// "PPPoE Aktif" atau buka/submit panel "Buat Akun PPPoE"), BUKAN dipoll
// terus-menerus seperti api/live.php, supaya tidak menambah beban ke router.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!mh_logged_in()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'not_logged_in'));
    exit;
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'active';

// Aksi yang MENGUBAH data wajib CSRF-token valid. Aksi baca (active, profiles)
// tidak butuh token karena tidak mengubah apa pun di router.
$writeActions = array('create');
if (in_array($action, $writeActions, true)) {
    if (!mh_csrf_check(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'CSRF tidak valid, muat ulang halaman lalu coba lagi.'));
        exit;
    }
}

$api = mh_connect_from_session();
if (!$api) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'Tidak bisa terhubung ke router.'));
    exit;
}

switch ($action) {
    case 'active':
        echo json_encode(array('ok' => true, 'list' => mh_fetch_pppoe_active_list($api)));
        break;

    case 'profiles':
        echo json_encode(array('ok' => true, 'profiles' => mh_fetch_ppp_profiles($api)));
        break;

    case 'create':
        $result = mh_create_pppoe_secret($api, array(
            'username'       => isset($_POST['username']) ? $_POST['username'] : '',
            'password'       => isset($_POST['password']) ? $_POST['password'] : '',
            'profile'        => isset($_POST['profile']) ? $_POST['profile'] : '',
            'comment'        => isset($_POST['comment']) ? $_POST['comment'] : '',
            'local_address'  => isset($_POST['local_address']) ? $_POST['local_address'] : '',
            'remote_address' => isset($_POST['remote_address']) ? $_POST['remote_address'] : '',
        ));
        if (empty($result['ok'])) {
            http_response_code(422);
            echo json_encode(array('ok' => false, 'error' => $result['error']));
        } else {
            echo json_encode(array('ok' => true, 'data' => $result));
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'unknown_action'));
}

$api->disconnect();
