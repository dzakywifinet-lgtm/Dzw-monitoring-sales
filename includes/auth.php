<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    session_start();
}

function mh_logged_in()
{
    return !empty($_SESSION['mh_host']) && !empty($_SESSION['mh_user']);
}

function mh_require_login()
{
    if (!mh_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function mh_csrf_token()
{
    if (empty($_SESSION['mh_csrf'])) {
        $_SESSION['mh_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['mh_csrf'];
}

function mh_csrf_check($token)
{
    return !empty($_SESSION['mh_csrf']) && hash_equals($_SESSION['mh_csrf'], (string) $token);
}

/** Buka koneksi API memakai kredensial yang tersimpan di sesi. */
function mh_connect_from_session()
{
    require_once __DIR__ . '/functions.php';
    return mh_connect(
        $_SESSION['mh_host'],
        $_SESSION['mh_user'],
        $_SESSION['mh_pass'],
        isset($_SESSION['mh_port']) ? $_SESSION['mh_port'] : 8728,
        isset($_SESSION['mh_ssl']) ? $_SESSION['mh_ssl'] : false
    );
}
