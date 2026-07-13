<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('customer');
$pageTitle = 'My Orders — ExpiryRush';
$uid = currentUserId();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $coid = (int)$_POST['order_id'];
    $chk = $conn->prepare(
        "SELECT id FROM orders WHERE id = ? AND customer_id = ? AND status = 'pending'"
    );
    $chk->bind_param('ii', $coid, $uid);
    $chk->execute();
    $chk->store_result();
    $canCancel = ($chk->num_rows > 0);
    $chk->close();
    if ($canCancel) {
        $conn->begin_transaction();
        try {
            $items = $conn->prepare(
                "SELECT product_id, quantity FROM order_items WHERE order_id = ?"
            );
            $items->bind_param('i', $coid);
            $items->execute();
            $itemRes = $items->get_result();
            $items->close();
            while ($it = $itemRes->fetch_assoc()) {
                $restoreStmt = $conn->prepare(
                    "UPDATE products SET stock = stock + ? WHERE id = ?"
                );
                $restoreStmt->bind_param('ii', $it['quantity'], $it['product_id']);
                $restoreStmt->execute();
                $restoreStmt->close();
            }
            $upd = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
            $upd->bind_param('i', $coid);
            $upd->execute();
            $upd->close();
            $pay = $conn->prepare("UPDATE payments SET status = 'failed' WHERE order_id = ?");
            $pay->bind_param('i', $coid);
            $pay->execute();
            $pay->close();
            $log = $conn->prepare(
                "INSERT INTO order_tracking (order_id, status, note, changed_by)
                 VALUES (?, 'cancelled', 'Cancelled by customer', ?)"
            );
            $log->bind_param('ii', $coid, $uid);
            $log->execute();
            $log->close();
            $conn->commit();
            setFlash('success', 'Order #' . str_pad($coid, 3, '0', STR_PAD_LEFT) . ' cancelled. Stock has been restored.');
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('error', 'Could not cancel order. Please try again.');
        }
    } else {
        setFlash('error', 'This order cannot be cancelled (only pending orders can be cancelled).');
    }
    header('Location: orders.php');
    exit;
}
$result = $conn->prepare("
    SELECT o.id, o.total_amount, o.status, o.created_at,
           o.delivery_address, o.delivery_city,
           GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') AS items,
           COUNT(oi.id) AS item_count,
           SUM(oi.quantity) AS total_qty
    FROM   orders o
    JOIN   order_items oi ON oi.order_id = o.id
    WHERE  o.customer_id = ?
    GROUP  BY o.id
    ORDER  BY o.created_at DESC
");
$result->bind_param('i', $uid);
$result->execute();
$orders = $result->get_result();
$result->close();
$statusSteps = ['pending', 'processing', 'out_for_delivery', 'delivered'];
$statusLabels = [
    'pending' => '⏳ Pending',
    'processing' => '⚙️ Processing',
    'out_for_delivery' => '🚚 Out for Delivery',
    'delivered' => '✅ Delivered',
    'cancelled' => '❌ Cancelled',
];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <h1>📦 MY ORDERS</h1>
    <p>Track your orders and view delivery status.</p>
</div>
<?php if (!$orders || $orders->num_rows === 0): ?>
<div class="empty-state">
    <div class="big">📦</div>
    <p>No orders yet. <a href="browse.php" style="color:var(--orange);">Go shopping!</a></p>
</div>
<?php else: ?>
<?php while ($o = $orders->fetch_assoc()):
    $canCancel = ($o['status'] === 'pending');
    $curIdx = array_search($o['status'], $statusSteps);
?>
<div class="order-card">
    <div class="order-header">
        <div>
            <span class="order-id">ORD-<?= str_pad($o['id'], 3, '0', STR_PAD_LEFT) ?></span>
            <span style="font-size:12px;color:var(--muted);margin-left:10px;">
                <?= date('d M Y, H:i', strtotime($o['created_at'])) ?>
            </span>
        </div>
        <span class="status-badge status-<?= str_replace('_', '-', e($o['status'])) ?>">
            <?= $statusLabels[$o['status']] ?? ucwords(str_replace('_', ' ', $o['status'])) ?>
        </span>
    </div>
    <?php if ($o['status'] !== 'cancelled' && $curIdx !== false): ?>
    <div style="display:flex;gap:2px;margin:10px 0;background:var(--bg);
                border-radius:6px;overflow:hidden;">
        <?php foreach ($statusSteps as $idx => $step): ?>
        <div style="flex:1;height:6px;background:<?= $idx <= $curIdx
            ? ($curIdx === count($statusSteps) - 1 ? 'var(--green)' : 'var(--orange)')
            : 'var(--border)' ?>;"></div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:10px;
                color:var(--muted);margin-bottom:10px;">
        <?php foreach ($statusSteps as $idx => $step): ?>
        <span style="color:<?= $idx <= $curIdx ? 'var(--text)' : 'var(--muted)' ?>;">
            <?= ['⏳','⚙️','🚚','✅'][$idx] ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="font-size:13px;color:var(--muted);margin-bottom:8px;">
        <?= e($o['items']) ?>
    </div>
    <?php if ($o['delivery_address']): ?>
    <div style="font-size:12px;color:var(--muted);margin-bottom:10px;">
        📍 <?= e($o['delivery_address']) ?><?= $o['delivery_city'] ? ', ' . e($o['delivery_city']) : '' ?>
    </div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;
                flex-wrap:wrap;gap:8px;">
        <span style="font-size:12px;color:var(--muted);">
            <?= (int)$o['total_qty'] ?> item(s) &nbsp;·&nbsp; 💵 Cash on Delivery
        </span>
        <div style="display:flex;gap:8px;align-items:center;">
            <span style="font-size:16px;font-weight:800;color:var(--orange);">
                RS.<?= number_format($o['total_amount'], 0) ?>
            </span>
            <a href="order_confirmation.php?id=<?= $o['id'] ?>"
               class="btn btn-sm btn-secondary">View Details</a>
            <?php if ($canCancel): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="cancel_order" value="1">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Cancel order #<?= str_pad($o['id'], 3, '0', STR_PAD_LEFT) ?>?\nStock will be restored.')">
                    Cancel
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endwhile; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>