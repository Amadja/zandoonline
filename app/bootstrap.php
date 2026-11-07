<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

$configPath = ROOT_PATH . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.example.php to config/config.php.');
}
$config = require $configPath;

if (($config['debug'] ?? false) === true) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

date_default_timezone_set('Africa/Kampala');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('zandoonline_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once ROOT_PATH . '/app/helpers.php';
require_once ROOT_PATH . '/app/database.php';
require_once ROOT_PATH . '/app/auth.php';
require_once ROOT_PATH . '/app/cart.php';
require_once ROOT_PATH . '/app/notifications.php';
