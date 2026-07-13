<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function requireRole(string|array $role): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/expiry_rush/') . 'index.php');
        exit;
    }
    $allowed = (array) $role;
    if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/expiry_rush/') . 'index.php');
        exit;
    }
}
if (!function_exists('currentUserId')) {
    function currentUserId(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}
if (!function_exists('currentRole')) {
    function currentRole(): string {
        return $_SESSION['role'] ?? '';
    }
}