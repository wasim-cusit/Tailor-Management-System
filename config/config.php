<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    session_start();
}

date_default_timezone_set('Asia/Karachi');

const APP_NAME = 'Tailor Management System';
const BASE_URL = '/Tailor Management System/v1';

const DB_HOST = '127.0.0.1';
const DB_NAME = 'tailor_management';
const DB_USER = 'root';
const DB_PASS = '';

