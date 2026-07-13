<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode(['count' => 0]);
    exit;
}
$uid = (int)$_SESSION['user_id'];
$stmt = $conn->prepare(
    "SELECT COALESCE(SUM(quantity), 0) AS total
     FROM   cart
     WHERE  customer_id = ? AND lock_expires_at > NOW()"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$count = (int)($row['total'] ?? 0);
$_SESSION['cart_count'] = $count;
echo json_encode(['count' => $count]);