<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('seller');
$pageTitle = 'Seller Dashboard — ExpiryRush';
$uid = currentUserId();
if ($conn->multi_query("CALL sp_seller_cleanup_expired($uid)")) {
    do { if ($r = $conn->store_result()) $r->free(); } while ($conn->more_results() && $conn->next_result());
}
$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM products WHERE seller_id = ? AND is_active = 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$totalProducts = (int)$stmt->get_result()->fetch_assoc()['n'];
$stmt->close();
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS rev
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE p.seller_id = ?
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$totalRevenue = (float)$stmt->get_result()->fetch_assoc()['rev'];
$stmt->close();
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT o.id) AS n
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE p.seller_id = ?
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$totalOrders = (int)$stmt->get_result()->fetch_assoc()['n'];
$stmt->close();
$stmt = $conn->prepare("
    SELECT COUNT(*) AS n FROM products
    WHERE seller_id = ? AND stock <= 2 AND is_active = 1 AND expires_at > NOW()
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$lowStock = (int)$stmt->get_result()->fetch_assoc()['n'];
$stmt->close();
$stmt = $conn->prepare("
    SELECT o.id, o.created_at, o.total_amount, o.status,
           GROUP_CONCAT(oi.product_name SEPARATOR ', ') AS items
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE p.seller_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$recentOrders = $stmt->get_result();
$stmt->close();
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
  <h1>📊 SELLER DASHBOARD</h1>
  <p>Welcome back, <?= e($_SESSION['name']) ?>!</p>
</div>
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon">📦</div>
    <div class="stat-num"><?= $totalProducts ?></div>
    <div class="stat-label">Active Products</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">💰</div>
    <div class="stat-num">RS.<?= number_format($totalRevenue, 0) ?></div>
    <div class="stat-label">Total Revenue</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🛒</div>
    <div class="stat-num"><?= $totalOrders ?></div>
    <div class="stat-label">Orders</div>
  </div>
  <div class="stat-card <?= $lowStock > 0 ? 'stat-warning' : '' ?>">
    <div class="stat-icon">⚠️</div>
    <div class="stat-num"><?= $lowStock ?></div>
    <div class="stat-label">Low Stock Items</div>
  </div>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;margin:24px 0 12px;">
  <h2 style="font-size:18px;font-weight:800;">Recent Orders</h2>
  <a href="products.php" class="btn btn-primary">+ Add Product</a>
</div>
<?php if (!$recentOrders || $recentOrders->num_rows === 0): ?>
<div class="empty-state">
  <div class="big">📋</div>
  <p>No orders yet. Add products to start selling!</p>
</div>
<?php else: ?>
<?php while ($o = $recentOrders->fetch_assoc()): ?>
<div class="order-card">
  <div class="order-header">
    <span class="order-id">ORD-<?= str_pad($o['id'], 3, '0', STR_PAD_LEFT) ?></span>
    <span class="status-badge status-<?= e($o['status']) ?>"><?= ucfirst(e($o['status'])) ?></span>
  </div>
  <div style="font-size:13px;color:var(--muted);margin-bottom:8px;"><?= e($o['items']) ?></div>
  <div style="display:flex;justify-content:space-between;">
    <span style="font-size:12px;color:var(--muted);"><?= date('d M Y, H:i', strtotime($o['created_at'])) ?></span>
    <span style="font-weight:800;color:var(--orange);">RS.<?= number_format($o['total_amount'], 0) ?></span>
  </div>
</div>
<?php endwhile; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>