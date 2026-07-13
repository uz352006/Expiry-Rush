<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('customer');
$pageTitle = 'Order Confirmed — ExpiryRush';
$uid = currentUserId();
$oid = (int)($_GET['id'] ?? 0);
if (!$oid) {
    header('Location: orders.php');
    exit;
}
$stmt = $conn->prepare("
    SELECT o.*, p.transaction_ref, p.method AS pay_method, p.status AS pay_status
    FROM   orders o
    LEFT JOIN payments p ON p.order_id = o.id
    WHERE  o.id = ? AND o.customer_id = ?
");
$stmt->bind_param('ii', $oid, $uid);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) {
    setFlash('error', 'Order not found.');
    header('Location: orders.php');
    exit;
}
$itemStmt = $conn->prepare("
    SELECT oi.*, c.name AS category_name
    FROM   order_items oi
    LEFT JOIN products   p  ON oi.product_id  = p.id
    LEFT JOIN categories c  ON p.category_id  = c.id
    WHERE  oi.order_id = ?
    ORDER BY oi.id
");
$itemStmt->bind_param('i', $oid);
$itemStmt->execute();
$itemsRes = $itemStmt->get_result();
$itemStmt->close();
$trackStmt = $conn->prepare("
    SELECT ot.status, ot.note, ot.changed_at
    FROM   order_tracking ot
    WHERE  ot.order_id = ?
    ORDER BY ot.changed_at ASC
");
$trackStmt->bind_param('i', $oid);
$trackStmt->execute();
$tracking = $trackStmt->get_result();
$trackStmt->close();
$catEmojis = [
    'Dairy' => '🥛', 'Bakery' => '🍞', 'Fruits' => '🍓',
    'Vegetables' => '🥗', 'Meat' => '🍗', 'Beverages' => '🧃',
    'Snacks' => '🍿', 'Frozen Foods' => '❄️',
];
$statusSteps = ['pending', 'processing', 'out_for_delivery', 'delivered'];
$statusLabels = ['⏳ Pending', '⚙️ Processing', '🚚 Out for Delivery', '✅ Delivered'];
$currentIdx = array_search($order['status'], $statusSteps);
require_once __DIR__ . '/header.php';
?>
<style>
@media print {
    nav, .no-print, .site-footer { display: none !important; }
    body { background: #fff; color: #000; }
    .receipt-box { border: 1px solid #ccc !important; box-shadow: none !important; }
    .form-card { background: #fff !important; border: 1px solid #ccc !important; }
}
</style>
<div class="page-header">
    <h1>✅ ORDER CONFIRMED</h1>
    <p>Thank you! Your order has been placed. Keep this page as your receipt.</p>
</div>
<?php if ($order['status'] !== 'cancelled'): ?>
<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;
            padding:20px;margin-bottom:20px;">
    <div style="display:flex;margin-bottom:8px;">
        <?php foreach ($statusSteps as $idx => $step):
            $done = $currentIdx !== false && $idx < $currentIdx;
            $active = $currentIdx === $idx;
            $bg = $done ? 'var(--green)' : ($active ? 'var(--orange)' : 'var(--border)');
            $color = ($done || $active) ? '#fff' : 'var(--muted)';
        ?>
        <div style="flex:1;text-align:center;padding:8px 4px;font-size:11px;font-weight:700;
                    background:<?= $bg ?>;color:<?= $color ?>;
                    <?= $idx === 0 ? 'border-radius:8px 0 0 8px;' : '' ?>
                    <?= $idx === count($statusSteps)-1 ? 'border-radius:0 8px 8px 0;' : '' ?>">
            <?= $statusLabels[$idx] ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($order['status'] === 'delivered'): ?>
    <p style="text-align:center;font-size:13px;color:var(--green);font-weight:700;margin-top:8px;">
        🎉 Your order has been delivered! Please pay the delivery person.
    </p>
    <?php elseif ($order['status'] === 'out_for_delivery'): ?>
    <p style="text-align:center;font-size:13px;color:var(--yellow);margin-top:8px;">
        🚚 Your order is on its way!
    </p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="flash flash-error" style="margin-bottom:20px;">
    ❌ This order was cancelled.
</div>
<?php endif; ?>
<div class="receipt-box form-card" style="max-width:700px;margin:0 auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;
                margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <div>
            <div style="font-size:24px;font-weight:900;">
                ORD-<?= str_pad($oid, 3, '0', STR_PAD_LEFT) ?>
            </div>
            <div style="font-size:13px;color:var(--muted);">
                Placed on <?= date('d M Y, H:i', strtotime($order['created_at'])) ?>
            </div>
        </div>
        <div style="text-align:right;">
            <span class="status-badge status-<?= str_replace('_', '-', e($order['status'])) ?>">
                <?= ucwords(str_replace('_', ' ', $order['status'])) ?>
            </span>
            <?php if ($order['transaction_ref']): ?>
            <div style="font-size:11px;color:var(--muted);margin-top:4px;">
                Ref: <?= e($order['transaction_ref']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
        <thead>
            <tr style="border-bottom:1px solid var(--border);">
                <th style="text-align:left;padding:8px;font-size:11px;
                           color:var(--muted);text-transform:uppercase;">Item</th>
                <th style="text-align:center;padding:8px;font-size:11px;
                           color:var(--muted);text-transform:uppercase;">Qty</th>
                <th style="text-align:right;padding:8px;font-size:11px;
                           color:var(--muted);text-transform:uppercase;">Price</th>
                <th style="text-align:right;padding:8px;font-size:11px;
                           color:var(--muted);text-transform:uppercase;">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $origTotal = 0;
        while ($item = $itemsRes->fetch_assoc()):
            $emoji = $catEmojis[$item['category_name'] ?? ''] ?? '🛒';
            $lineTotal = (float)$item['unit_price'] * (int)$item['quantity'];
            $discPct = (float)$item['discount_pct'];
            $origPrice = $discPct > 0 ? $item['unit_price'] / (1 - $discPct / 100) : $item['unit_price'];
            $origTotal += $origPrice * $item['quantity'];
        ?>
        <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:10px 8px;">
                <?= $emoji ?> <?= e($item['product_name']) ?>
                <?php if ($item['discount_pct'] > 0): ?>
                <span class="disc-badge" style="font-size:10px;vertical-align:middle;">
                    -<?= (int)$item['discount_pct'] ?>%
                </span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;padding:10px 8px;color:var(--muted);">
                <?= (int)$item['quantity'] ?>
            </td>
            <td style="text-align:right;padding:10px 8px;">
                RS.<?= number_format($item['unit_price'], 0) ?>
            </td>
            <td style="text-align:right;padding:10px 8px;font-weight:700;color:var(--orange);">
                RS.<?= number_format($lineTotal, 0) ?>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
        <tfoot>
            <?php if ($origTotal > $order['total_amount']): ?>
            <tr>
                <td colspan="3" style="text-align:right;padding:8px;font-size:13px;color:var(--muted);">
                    You Saved:
                </td>
                <td style="text-align:right;padding:8px;color:var(--green);font-weight:700;">
                    RS.<?= number_format($origTotal - $order['total_amount'], 0) ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td colspan="3"
                    style="text-align:right;padding:12px 8px;font-weight:800;font-size:16px;">
                    Total Payable (COD):
                </td>
                <td style="text-align:right;padding:12px 8px;font-weight:900;
                           font-size:18px;color:var(--orange);">
                    RS.<?= number_format($order['total_amount'], 0) ?>
                </td>
            </tr>
        </tfoot>
    </table>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;
                padding:16px;background:var(--bg);border-radius:10px;margin-bottom:16px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:var(--muted);
                        text-transform:uppercase;margin-bottom:6px;">📍 Delivery Address</div>
            <div style="font-size:14px;font-weight:700;"><?= e($order['delivery_name']) ?></div>
            <div style="font-size:13px;color:var(--muted);"><?= e($order['delivery_phone']) ?></div>
            <div style="font-size:13px;color:var(--muted);">
                <?= e($order['delivery_address']) ?>
                <?= $order['delivery_city'] ? ', ' . e($order['delivery_city']) : '' ?>
            </div>
            <?php if ($order['notes']): ?>
            <div style="font-size:12px;color:var(--muted);font-style:italic;margin-top:4px;">
                Note: <?= e($order['notes']) ?>
            </div>
            <?php endif; ?>
        </div>
        <div>
            <div style="font-size:11px;font-weight:700;color:var(--muted);
                        text-transform:uppercase;margin-bottom:6px;">💵 Payment</div>
            <div style="font-size:14px;font-weight:700;">Cash on Delivery</div>
            <div style="font-size:13px;color:var(--muted);">
                Pay RS.<?= number_format($order['total_amount'], 0) ?> on arrival
            </div>
            <div style="font-size:12px;margin-top:6px;color:<?=
                $order['pay_status'] === 'success' ? 'var(--green)'
                : ($order['pay_status'] === 'failed' ? 'var(--red)' : 'var(--yellow)') ?>;">
                <?= $order['pay_status'] === 'success' ? '✅ Payment collected'
                  : ($order['pay_status'] === 'failed' ? '❌ Payment not collected'
                  : '⏳ Awaiting delivery') ?>
            </div>
            <?php if ($order['status'] !== 'cancelled' && $order['status'] !== 'delivered'): ?>
            <div style="font-size:12px;color:var(--green);margin-top:6px;">
                🚚 Est. delivery: 3–5 business days
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($tracking && $tracking->num_rows > 0): ?>
    <div style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:800;margin-bottom:10px;">📋 Order History</div>
        <?php while ($t = $tracking->fetch_assoc()): ?>
        <div style="display:flex;gap:12px;align-items:flex-start;
                    padding:8px 0;border-bottom:1px solid var(--border);">
            <div style="font-size:12px;color:var(--muted);white-space:nowrap;min-width:90px;">
                <?= date('d M, H:i', strtotime($t['changed_at'])) ?>
            </div>
            <div>
                <span class="status-badge status-<?= str_replace('_', '-', e($t['status'])) ?>">
                    <?= ucwords(str_replace('_', ' ', $t['status'])) ?>
                </span>
                <?php if ($t['note']): ?>
                <span style="font-size:12px;color:var(--muted);margin-left:8px;">
                    <?= e($t['note']) ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>
    <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="orders.php" class="btn btn-secondary">📦 My Orders</a>
        <a href="browse.php" class="btn btn-primary">🛒 Continue Shopping</a>
        <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Receipt</button>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>