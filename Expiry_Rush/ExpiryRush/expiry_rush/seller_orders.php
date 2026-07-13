<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('seller');
$pageTitle = 'My Orders — Seller — ExpiryRush';
$uid = currentUserId();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $oid = (int)$_POST['order_id'];
    $status = trim($_POST['new_status'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $allowed = ['processing', 'out_for_delivery', 'delivered', 'cancelled'];
    if (!$oid || !in_array($status, $allowed, true)) {
        setFlash('error', 'Invalid status.');
        header('Location: seller_orders.php');
        exit;
    }
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
        setFlash('error', 'Access denied — this order is not yours.');
        header('Location: seller_orders.php');
        exit;
    }
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $upd->bind_param('si', $status, $oid);
        $upd->execute();
        $upd->close();
        $log = $conn->prepare(
            "INSERT INTO order_tracking (order_id, status, note, changed_by) VALUES (?, ?, ?, ?)"
        );
        $log->bind_param('issi', $oid, $status, $note, $uid);
        $log->execute();
        $log->close();
        if ($status === 'cancelled') {
            $items = $conn->prepare("
                SELECT oi.product_id, oi.quantity FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ? AND p.seller_id = ?
            ");
            $items->bind_param('ii', $oid, $uid);
            $items->execute();
            $ir = $items->get_result();
            $items->close();
            while ($it = $ir->fetch_assoc()) {
                $rst = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $rst->bind_param('ii', $it['quantity'], $it['product_id']);
                $rst->execute();
                $rst->close();
            }
        }
        if ($status === 'delivered') {
            $pay = $conn->prepare(
                "UPDATE payments SET status = 'success', paid_at = NOW() WHERE order_id = ?"
            );
            $pay->bind_param('i', $oid);
            $pay->execute();
            $pay->close();
        }
        $conn->commit();
        setFlash('success', 'Order updated to ' . ucwords(str_replace('_', ' ', $status)) . '.');
    } catch (Exception $e) {
        $conn->rollback();
        setFlash('error', 'Update failed. Please try again.');
    }
    header('Location: seller_orders.php' . ($_GET['status'] ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}
$filterStatus = $_GET['status'] ?? 'all';
$validStatuses = ['pending','processing','out_for_delivery','delivered','cancelled'];
$whereStatus = '';
if (in_array($filterStatus, $validStatuses, true)) {
    $fs = $conn->real_escape_string($filterStatus);
    $whereStatus = "AND o.status = '{$fs}'";
}
$orders = $conn->query("
    SELECT DISTINCT
           o.id, o.total_amount, o.status, o.created_at,
           o.delivery_name, o.delivery_phone, o.delivery_address, o.delivery_city, o.notes,
           u.name AS customer_name,
           u.email AS customer_email
    FROM   orders o
    JOIN   order_items oi ON oi.order_id = o.id
    JOIN   products p ON oi.product_id = p.id
    JOIN   users u ON o.customer_id = u.id
    WHERE  p.seller_id = {$uid} {$whereStatus}
    ORDER  BY o.created_at DESC
");
$statusOptions = [
    'all' => 'All Orders',
    'pending' => '⏳ Pending',
    'processing' => '⚙️ Processing',
    'out_for_delivery' => '🚚 Out for Delivery',
    'delivered' => '✅ Delivered',
    'cancelled' => '❌ Cancelled',
];
$nextStatus = [
    'pending' => 'processing',
    'processing' => 'out_for_delivery',
    'out_for_delivery' => 'delivered',
];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <h1>📦 SELLER ORDERS</h1>
    <p>Manage delivery status for orders containing your products.</p>
</div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;">
    <?php foreach ($statusOptions as $val => $label): ?>
    <a href="seller_orders.php?status=<?= $val ?>"
       class="btn <?= $filterStatus === $val ? 'btn-primary' : 'btn-secondary' ?>"
       style="font-size:12px;">
        <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>
<?php if (!$orders || $orders->num_rows === 0): ?>
<div class="empty-state">
    <div class="big">📋</div>
    <p>No orders found<?= $filterStatus !== 'all' ? ' with this status' : '' ?>.</p>
</div>
<?php else: while ($o = $orders->fetch_assoc()):
    $next = $nextStatus[$o['status']] ?? null;
    $itemsQ = $conn->prepare("
        SELECT oi.product_name, oi.quantity, oi.unit_price, oi.discount_pct
        FROM   order_items oi
        JOIN   products p ON oi.product_id = p.id
        WHERE  oi.order_id = ? AND p.seller_id = ?
    ");
    $itemsQ->bind_param('ii', $o['id'], $uid);
    $itemsQ->execute();
    $itemsRes = $itemsQ->get_result();
    $itemsQ->close();
?>
<div class="order-card" style="margin-bottom:16px;">
    <div class="order-header">
        <div>
            <span class="order-id">ORD-<?= str_pad($o['id'], 3, '0', STR_PAD_LEFT) ?></span>
            <span style="font-size:12px;color:var(--muted);margin-left:8px;">
                <?= date('d M Y, H:i', strtotime($o['created_at'])) ?>
            </span>
        </div>
        <span class="status-badge status-<?= str_replace('_', '-', e($o['status'])) ?>">
            <?= ucwords(str_replace('_', ' ', $o['status'])) ?>
        </span>
    </div>
    <div style="margin:10px 0;padding:10px;background:var(--bg);border-radius:8px;font-size:13px;">
        <?php while ($it = $itemsRes->fetch_assoc()): ?>
        <div style="display:flex;justify-content:space-between;padding:3px 0;">
            <span>
                <?= e($it['product_name']) ?> × <?= (int)$it['quantity'] ?>
                <?php if ($it['discount_pct'] > 0): ?>
                <span class="disc-badge" style="font-size:10px;vertical-align:middle;">
                    -<?= (int)$it['discount_pct'] ?>%
                </span>
                <?php endif; ?>
            </span>
            <span style="color:var(--orange);">
                RS.<?= number_format($it['unit_price'] * $it['quantity'], 0) ?>
            </span>
        </div>
        <?php endwhile; ?>
        <div style="border-top:1px solid var(--border);margin-top:6px;padding-top:6px;
                    font-weight:800;display:flex;justify-content:space-between;">
            <span>Total (COD)</span>
            <span style="color:var(--orange);">RS.<?= number_format($o['total_amount'], 0) ?></span>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;
                font-size:13px;margin-bottom:12px;">
        <div>
            <div style="font-size:11px;color:var(--muted);font-weight:700;
                        text-transform:uppercase;margin-bottom:4px;">Customer</div>
            <div><?= e($o['customer_name']) ?></div>
            <div style="color:var(--muted);"><?= e($o['customer_email']) ?></div>
        </div>
        <div>
            <div style="font-size:11px;color:var(--muted);font-weight:700;
                        text-transform:uppercase;margin-bottom:4px;">Deliver To</div>
            <div style="font-weight:700;"><?= e($o['delivery_name']) ?></div>
            <div style="color:var(--muted);"><?= e($o['delivery_phone']) ?></div>
            <div style="color:var(--muted);">
                <?= e($o['delivery_address']) ?>
                <?= $o['delivery_city'] ? ', ' . e($o['delivery_city']) : '' ?>
            </div>
            <?php if ($o['notes']): ?>
            <div style="font-size:12px;color:var(--muted);font-style:italic;">
                Note: <?= e($o['notes']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($o['status'] !== 'delivered' && $o['status'] !== 'cancelled'): ?>
    <div style="padding-top:12px;border-top:1px solid var(--border);">
        <form method="POST" action="seller_orders.php"
              style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <input type="text" name="note"
                   placeholder="Optional tracking note…"
                   style="flex:1;min-width:160px;padding:7px 12px;font-size:13px;
                          border-radius:7px;background:var(--bg);
                          border:1px solid var(--border);color:var(--text);">
            <?php if ($next): ?>
            <button type="submit" name="new_status" value="<?= $next ?>"
                    class="btn btn-primary btn-sm"
                    onclick="return confirm('Mark as <?= ucwords(str_replace('_', ' ', $next)) ?>?')">
                → Mark as <?= ucwords(str_replace('_', ' ', $next)) ?>
            </button>
            <?php endif; ?>
            <?php if ($o['status'] === 'pending'): ?>
            <button type="submit" name="new_status" value="cancelled"
                    class="btn btn-danger btn-sm"
                    onclick="return confirm('Cancel this order? Stock will be restored.')">
                Cancel Order
            </button>
            <?php endif; ?>
        </form>
    </div>
    <?php else: ?>
    <div style="padding-top:10px;font-size:12px;color:var(--muted);
                border-top:1px solid var(--border);">
        <?= $o['status'] === 'delivered' ? '✅ Order completed — payment collected.' : '❌ Order cancelled.' ?>
    </div>
    <?php endif; ?>
</div>
<?php endwhile; endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>