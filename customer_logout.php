<?php
require_once __DIR__ . '/config/config.php';

unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_code']);
session_regenerate_id(true);
header('Location: ' . BASE_URL . '/customer_login.php');
exit;

