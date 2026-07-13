<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('admin');
$pageTitle = 'Manage Orders — ExpiryRush';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $oid = (int)$_POST['order_id'];
    $status = trim($_POST['new_status'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $aid = currentUserId();
    $allowed = ['pending', 'processing', 'out_for_delivery', 'delivered', 'cancelled'];
    if ($oid && in_array($status, $allowed, true)) {
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $upd->bind_param('si', $status, $oid);
            $upd->execute();
            $upd->close();
            $log = $conn->prepare(
                "INSERT INTO order_tracking (order_id, status, note, changed_by) VALUES (?, ?, ?, ?)"
            );
            $log->bind_param('issi', $oid, $status, $note, $aid);
            $log->execute();
            $log->close();
            if ($status === 'cancelled') {
                $items = $conn->prepare(
                    "SELECT product_id, quantity FROM order_items WHERE order_id = ?"
                );
                $items->bind_param('i', $oid);
                $items->execute();
                $ir = $items->get_result();
                $items->close();
                while ($it = $ir->fetch_assoc()) {
                    $rst = $conn->prepare(
                        "UPDATE products SET stock = stock + ? WHERE id = ?"
                    );
                    $rst->bind_param('ii', $it['quantity'], $it['product_id']);
                    $rst->execute();
                    $rst->close();
                }
                $pay = $conn->prepare(
                    "UPDATE payments SET status = 'failed' WHERE order_id = ?"
                );
                $pay->bind_param('i', $oid);
                $pay->execute();
                $pay->close();
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
            setFlash(
                'success',
                'Order #' . str_pad($oid, 3, '0', STR_PAD_LEFT)
                . ' updated to ' . ucwords(str_replace('_', ' ', $status)) . '.'
            );
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('error', 'Update failed. Please try again.');
        }
    }
    $qs = http_build_query(array_filter([
        'status' => $_POST['current_filter'] ?? '',
        'q' => $_POST['current_search'] ?? '',
    ]));
    header('Location: admin_orders.php' . ($qs ? '?' . $qs : ''));
    exit;
}
$filterStatus = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$where = 'WHERE 1=1';
$validStatuses = ['pending', 'processing', 'out_for_delivery', 'delivered', 'cancelled'];
if (in_array($filterStatus, $validStatuses, true)) {
    $fs = $conn->real_escape_string($filterStatus);
    $where .= " AND o.status = '{$fs}'";
}
if ($search) {
    $s = $conn->real_escape_string($search);
    $oid_s = (int)$search;
    $where .= " AND (u.name LIKE '%{$s}%' OR u.email LIKE '%{$s}%' OR o.id = {$oid_s})";
}
$orders = $conn->query("
    SELECT o.id, o.total_amount, o.status, o.created_at,
           o.delivery_name, o.delivery_phone, o.delivery_address, o.delivery_city,
           u.name  AS customer_name,
           u.email AS customer_email,
           GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') AS items,
           COUNT(oi.id)     AS item_count,
           SUM(oi.quantity) AS total_qty
    FROM   orders o
    JOIN   users       u  ON o.customer_id = u.id
    JOIN   order_items oi ON oi.order_id   = o.id
    {$where}
    GROUP  BY o.id
    ORDER  BY o.created_at DESC
    LIMIT 200
");
$statusOptions = [
    'all' => 'All',
    'pending' => '⏳ Pending',
    'processing' => '⚙️ Processing',
    'out_for_delivery' => '🚚 Out for Delivery',
    'delivered' => '✅ Delivered',
    'cancelled' => '❌ Cancelled',
];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <h1>📊 MANAGE ORDERS</h1>
    <p>View and update all platform orders.</p>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:8px;flex:1;min-width:260px;">
        <input type="text" name="q" value="<?= e($search) ?>"
               placeholder="🔍 Search customer / order ID…"
               style="flex:1;padding:8px 12px;border-radius:8px;background:var(--card);
                      border:1px solid var(--border);color:var(--text);font-size:13px;">
        <?php if ($filterStatus !== 'all'): ?>
        <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search || $filterStatus !== 'all'): ?>
        <a href="admin_orders.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </form>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php foreach ($statusOptions as $val => $label): ?>
        <a href="admin_orders.php?status=<?= $val ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
           class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-secondary' ?>"
           style="font-size:11px;">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php if (!$orders || $orders->num_rows === 0): ?>
<div class="empty-state">
    <div class="big">📋</div>
    <p>No orders found.</p>
</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="data-table">
    <thead>
        <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Deliver To</th>
            <th>Items</th>
            <th>Total</th>
            <th>Date</th>
            <th>Status</th>
            <th>Update</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($o = $orders->fetch_assoc()): ?>
    <tr id="row-<?= $o['id'] ?>">
        <td><strong>ORD-<?= str_pad($o['id'], 3, '0', STR_PAD_LEFT) ?></strong></td>
        <td>
            <div><?= e($o['customer_name']) ?></div>
            <div style="font-size:11px;color:var(--muted);"><?= e($o['customer_email']) ?></div>
        </td>
        <td style="font-size:12px;">
            <div><?= e($o['delivery_name']) ?></div>
            <div style="color:var(--muted);"><?= e($o['delivery_phone']) ?></div>
            <div style="color:var(--muted);">
                <?= e($o['delivery_address']) ?>
                <?= $o['delivery_city'] ? ', ' . e($o['delivery_city']) : '' ?>
            </div>
        </td>
        <td style="font-size:12px;max-width:180px;">
            <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                 title="<?= e($o['items']) ?>">
                <?= e($o['items']) ?>
            </div>
            <div style="color:var(--muted);"><?= (int)$o['total_qty'] ?> item(s)</div>
        </td>
        <td style="font-weight:800;color:var(--orange);white-space:nowrap;">
            RS.<?= number_format($o['total_amount'], 0) ?>
        </td>
        <td style="font-size:12px;white-space:nowrap;">
            <?= date('d M Y', strtotime($o['created_at'])) ?><br>
            <span style="color:var(--muted);"><?= date('H:i', strtotime($o['created_at'])) ?></span>
        </td>
        <td>
            <span class="status-badge status-<?= str_replace('_', '-', e($o['status'])) ?>">
                <?= ucwords(str_replace('_', ' ', $o['status'])) ?>
            </span>
        </td>
        <td>
            <?php if (!in_array($o['status'], ['delivered', 'cancelled'], true)): ?>
            <form method="POST" action="admin_orders.php"
                  style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;min-width:220px;">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <input type="hidden" name="current_filter" value="<?= e($filterStatus) ?>">
                <input type="hidden" name="current_search" value="<?= e($search) ?>">
                <select name="new_status"
                        class="filter-select"
                        style="padding:5px 8px;font-size:12px;">
                    <?php
                    $stOpts = ['pending','processing','out_for_delivery','delivered','cancelled'];
                    foreach ($stOpts as $st):
                        if ($st === $o['status']) continue;
                    ?>
                    <option value="<?= $st ?>"><?= ucwords(str_replace('_', ' ', $st)) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="note" placeholder="Note…"
                       style="width:90px;padding:5px 8px;font-size:12px;
                              border-radius:6px;background:var(--bg);
                              border:1px solid var(--border);color:var(--text);">
                <button type="submit" class="btn btn-sm btn-primary"
                        onclick="return confirm('Update this order status?')">
                    Save
                </button>
            </form>
            <?php else: ?>
            <span style="font-size:12px;color:var(--muted);">Final</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>