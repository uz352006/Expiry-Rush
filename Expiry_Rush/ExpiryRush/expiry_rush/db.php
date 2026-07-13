<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'expiry_rush');
define('APP_NAME', 'Expiry Rush');
define('APP_URL', 'http://localhost/expiry_rush');
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
define('CART_LOCK_MINUTES', 15);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
function get_db(): mysqli {
    global $conn;
    return $conn;
}
if (!function_exists('e')) {
    function e(string $str = ''): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}