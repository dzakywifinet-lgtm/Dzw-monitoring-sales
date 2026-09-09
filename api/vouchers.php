<?php
// Endpoint BARU untuk panel "Generate Hotspot & PPPoE" dan modal detail
// Hotspot aktif - dipanggil hanya saat dibutuhkan (buka modal/panel atau klik
// tombol Generate), BUKAN dipoll terus-menerus seperti api/live.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!mh_logged_in()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'not_logged_in'));
    exit;
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'summary';

// Generate voucher = operasi TULIS ke router -> wajib CSRF-token valid.
$writeActions = array('generate', 'delete', 'delete_bulk');
if (in_array($action, $writeActions, true)) {
    if (!mh_csrf_check(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'CSRF tidak valid, muat ulang halaman lalu coba lagi.'));
        exit;
    }
    // Generate voucher / hapus massal = operasi TULIS berulang ke router -> naikkan
    // batas eksekusi PHP mengikuti jumlah item yang diproses.
    if ($action === 'generate') {
        $reqCount = max(1, min(500, (int) (isset($_POST['count']) ? $_POST['count'] : 1)));
        @set_time_limit(min(600, 60 + ($reqCount * 5)));
    } elseif ($action === 'delete_bulk') {
        $namesCount = isset($_POST['names']) && is_array($_POST['names']) ? count($_POST['names']) : 1;
        @set_time_limit(min(600, 60 + ($namesCount * 3)));
    }
}

$api = mh_connect_from_session();
if (!$api) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'Tidak bisa terhubung ke router.'));
    exit;
}

switch ($action) {
    case 'hotspot_active':
        echo json_encode(array('ok' => true, 'list' => mh_fetch_hotspot_active_list($api)));
        break;

    case 'summary':
        echo json_encode(array(
            'ok'       => true,
            'profiles' => mh_fetch_hotspot_profiles($api),
            'servers'  => mh_fetch_hotspot_servers($api),
            'summary'  => mh_fetch_hotspot_voucher_summary($api),
        ));
        break;

    case 'list_by_profile':
        $profile = isset($_GET['profile']) ? trim((string) $_GET['profile']) : '';
        if ($profile === '') {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'Profile wajib diisi.'));
            break;
        }
        echo json_encode(array(
            'ok'      => true,
            'profile' => $profile,
            'list'    => mh_fetch_hotspot_users_by_profile($api, $profile),
            // Info Harga/Masa Aktif/Kunci User dari profile (gaya Mikhmon) -
            // dipakai panel Cetak supaya nota voucher menampilkan field yang
            // sama seperti nota Mikhmon, walau voucher dibuat lewat fitur
            // Generate dashboard ini.
            'pricing' => mh_fetch_hotspot_profile_pricing($api, $profile),
        ));
        break;

    case 'delete':
        $name = isset($_POST['name']) ? $_POST['name'] : '';
        $result = mh_delete_hotspot_user($api, $name);
        if (empty($result['ok'])) {
            http_response_code(422);
            echo json_encode(array('ok' => false, 'error' => $result['error']));
        } else {
            echo json_encode(array('ok' => true, 'name' => $result['name']));
        }
        break;

    case 'delete_bulk':
        $names = isset($_POST['names']) ? $_POST['names'] : array();
        if (!is_array($names)) { $names = array(); }
        $names = array_filter(array_map('trim', $names), function ($n) { return $n !== ''; });
        if (empty($names)) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'Tidak ada voucher yang dipilih untuk dihapus.'));
            break;
        }
        $result = mh_delete_hotspot_users_bulk($api, $names);
        echo json_encode(array('ok' => true, 'deleted' => $result['deleted'], 'failed' => $result['failed']));
        break;

    case 'generate':
        $result = mh_generate_hotspot_vouchers($api, array(
            'count'           => isset($_POST['count']) ? $_POST['count'] : 1,
            'server'          => isset($_POST['server']) ? $_POST['server'] : 'all',
            'mode'            => isset($_POST['mode']) ? $_POST['mode'] : 'same',
            'name_length'     => isset($_POST['name_length']) ? $_POST['name_length'] : 6,
            'prefix'          => isset($_POST['prefix']) ? $_POST['prefix'] : '',
            'charset'         => isset($_POST['charset']) ? $_POST['charset'] : 'mixed_safe',
            'profile'         => isset($_POST['profile']) ? $_POST['profile'] : '',
            'time_limit'      => isset($_POST['time_limit']) ? $_POST['time_limit'] : '',
            'data_limit'      => isset($_POST['data_limit']) ? $_POST['data_limit'] : '',
            'data_limit_unit' => isset($_POST['data_limit_unit']) ? $_POST['data_limit_unit'] : 'MB',
            'comment'         => isset($_POST['comment']) ? $_POST['comment'] : '',
        ));
        echo json_encode(array(
            'ok'              => !empty($result['created']),
            'created'         => $result['created'],
            'failed'          => $result['failed'],
            'connection_lost' => $result['connection_lost'],
        ));
        break;

    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'unknown_action'));
}

$api->disconnect();
