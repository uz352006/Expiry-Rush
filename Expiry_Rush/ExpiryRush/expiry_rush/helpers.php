<?php
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
function calcDiscount(string $listed, string $expires): int {
    $now = time();
    $start = strtotime($listed);
    $end = strtotime($expires);
    $total = $end - $start;
    if ($total <= 0) return 90;
    $elapsed = $now - $start;
    $pct = ($elapsed / $total) * 90;
    return (int) max(0, min(90, round($pct)));
}
function timeLeft(string $expires): string {
    $diff = strtotime($expires) - time();
    if ($diff <= 0) return 'Expired';
    if ($diff < 60) return $diff . 's left';
    if ($diff < 3600) return floor($diff / 60) . 'm left';
    if ($diff < 86400) return floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm left';
    return floor($diff / 86400) . 'd left';
}
function timerClass(string $expires): string {
    $diff = strtotime($expires) - time();
    if ($diff < 3600) return 'timer timer-red';
    if ($diff < 21600) return 'timer timer-orange';
    return 'timer timer-green';
}
function barWidth(string $listed, string $expires): int {
    $now = time();
    $start = strtotime($listed);
    $end = strtotime($expires);
    $total = $end - $start;
    if ($total <= 0) return 100;
    $elapsed = $now - $start;
    return (int) max(0, min(100, round(($elapsed / $total) * 100)));
}
function barColor(int $discount): string {
    if ($discount >= 60) return '#ef4444';
    if ($discount >= 30) return '#f97316';
    return '#22c55e';
}
function setFlash(string $type, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['_flash'] = ['type' => $type, 'msg' => $msg];
}
function getFlash(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['_flash'])) return null;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}
function showFlash(): void {
    $f = getFlash();
    if ($f) {
        echo '<div class="flash flash-' . htmlspecialchars($f['type']) . '">'
           . htmlspecialchars($f['msg']) . '</div>';
    }
}
if (!function_exists('e')) {
    function e(string $str = ''): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}
function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}