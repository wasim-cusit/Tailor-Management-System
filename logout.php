<?php
require_once __DIR__ . '/config/config.php';
session_unset();
session_destroy();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
}
header('Location: ' . BASE_URL . '/login.php');
exit;

