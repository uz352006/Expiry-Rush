<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['seller', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$oid = (int)($_POST['order_id'] ?? 0);
$status = trim($_POST['status'] ?? '');
$note = trim($_POST['note'] ?? '');
$allowed = ['pending','processing','out_for_delivery','delivered','cancelled'];
if (!$oid || !in_array($status, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}
if ($role === 'seller') {
    $chk = $conn->prepare("
        SELECT COUNT(*) AS n FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ? AND p.seller_id = ?
    ");
    $chk->bind_param('ii', $oid, $uid);
    $chk->execute();
    $n = (int)$chk->get_result()->fetch_assoc()['n'];
    $chk->close();
    if ($n === 0) {
        echo json_encode(['ok' => false, 'error' => 'Order not found']);
        exit;
    }
}
$conn->begin_transaction();
try {
    $upd = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $upd->bind_param('si', $status, $oid);
    $upd->execute();
    $upd->close();
    if ($conn->affected_rows === 0) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Order not found']);
        exit;
    }
    $log = $conn->prepare("INSERT INTO order_tracking (order_id, status, note, changed_by) VALUES (?, ?, ?, ?)");
    $log->bind_param('issi', $oid, $status, $note, $uid);
    $log->execute();
    $log->close();
    if ($status === 'cancelled') {
        $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = {$oid}");
        while ($it = $items->fetch_assoc()) {
            $conn->query("UPDATE products SET stock = stock + {$it['quantity']} WHERE id = {$it['product_id']}");
        }
        $conn->query("UPDATE payments SET status = 'failed' WHERE order_id = {$oid}");
    }
    if ($status === 'delivered') {
        $conn->query("UPDATE payments SET status = 'success', paid_at = NOW() WHERE order_id = {$oid}");
    }
    $conn->commit();
    echo json_encode(['ok' => true, 'status' => $status]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}