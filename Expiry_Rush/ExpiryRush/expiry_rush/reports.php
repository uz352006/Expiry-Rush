<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('admin');
$pageTitle = 'Reports — ExpiryRush';
$summary = null;
if ($conn->multi_query("CALL sp_admin_platform_report()")) {
    $summaryResult = $conn->store_result();
    if ($summaryResult) {
        $summary = $summaryResult->fetch_assoc();
        $summaryResult->free();
    }
    while ($conn->more_results()) $conn->next_result();
}
if (!$summary) {
    $summary = [
        'total_revenue' => $conn->query("SELECT COALESCE(SUM(total_amount),0) AS n FROM orders WHERE status='completed'")->fetch_assoc()['n'],
        'total_orders' => $conn->query("SELECT COUNT(*) AS n FROM orders WHERE status='completed'")->fetch_assoc()['n'],
        'active_customers' => $conn->query("SELECT COUNT(*) AS n FROM users WHERE role='customer' AND is_active=1")->fetch_assoc()['n'],
        'active_sellers' => $conn->query("SELECT COUNT(*) AS n FROM users WHERE role='seller' AND is_active=1")->fetch_assoc()['n'],
        'live_products' => $conn->query("SELECT COUNT(*) AS n FROM active_products")->fetch_assoc()['n'],
        'expired_unsold' => $conn->query("SELECT COUNT(*) AS n FROM products WHERE expires_at < NOW() AND is_active=1")->fetch_assoc()['n'],
    ];
}
$topProducts = $conn->query("
    SELECT oi.product_name, COUNT(*) AS qty, SUM(oi.unit_price) AS revenue
    FROM order_items oi
    GROUP BY oi.product_name
    ORDER BY qty DESC
    LIMIT 10
");
$catRevenue = $conn->query("
    SELECT c.name AS category, SUM(oi.unit_price * oi.quantity) AS revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    GROUP BY c.name
    ORDER BY revenue DESC
");
$recentOrders = $conn->query("
    SELECT order_id AS id, created_at, total_amount, status, customer_name
    FROM order_summary
    ORDER BY created_at DESC
    LIMIT 10
");
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
  <h1>📊 PLATFORM REPORTS</h1>
</div>
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon">💰</div>
    <div class="stat-num">RS.<?= number_format($summary['total_revenue'], 0) ?></div>
    <div class="stat-label">Total Revenue</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🛒</div>
    <div class="stat-num"><?= (int)$summary['total_orders'] ?></div>
    <div class="stat-label">Completed Orders</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">👤</div>
    <div class="stat-num"><?= (int)$summary['active_customers'] ?></div>
    <div class="stat-label">Customers</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🏪</div>
    <div class="stat-num"><?= (int)$summary['active_sellers'] ?></div>
    <div class="stat-label">Sellers</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📦</div>
    <div class="stat-num"><?= (int)$summary['live_products'] ?></div>
    <div class="stat-label">Active Products</div>
  </div>
  <div class="stat-card stat-warning">
    <div class="stat-icon">⚠️</div>
    <div class="stat-num"><?= (int)$summary['expired_unsold'] ?></div>
    <div class="stat-label">Expired (Unsold)</div>
  </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:24px;flex-wrap:wrap;">
  <div class="form-card">
    <h2 style="font-size:16px;font-weight:800;margin-bottom:16px;">🏆 Top Selling Products</h2>
    <?php if ($topProducts && $topProducts->num_rows > 0): ?>
    <table class="data-table">
      <thead><tr><th>Product</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php while ($p = $topProducts->fetch_assoc()): ?>
      <tr>
        <td><?= e($p['product_name']) ?></td>
        <td><?= (int)$p['qty'] ?></td>
        <td>RS.<?= number_format($p['revenue'], 0) ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="color:var(--muted)">No orders yet.</p>
    <?php endif; ?>
  </div>
  <div class="form-card">
    <h2 style="font-size:16px;font-weight:800;margin-bottom:16px;">📂 Revenue by Category</h2>
    <?php if ($catRevenue && $catRevenue->num_rows > 0): ?>
    <table class="data-table">
      <thead><tr><th>Category</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php while ($c = $catRevenue->fetch_assoc()): ?>
      <tr>
        <td><?= e($c['category']) ?></td>
        <td>RS.<?= number_format($c['revenue'], 0) ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="color:var(--muted)">No data yet.</p>
    <?php endif; ?>
  </div>
</div>
<div class="form-card" style="margin-top:20px;">
  <h2 style="font-size:16px;font-weight:800;margin-bottom:16px;">🕒 Recent Orders</h2>
  <?php if ($recentOrders && $recentOrders->num_rows > 0): ?>
  <table class="data-table">
    <thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php while ($o = $recentOrders->fetch_assoc()): ?>
    <tr>
      <td>ORD-<?= str_pad($o['id'], 3, '0', STR_PAD_LEFT) ?></td>
      <td><?= e($o['customer_name']) ?></td>
      <td>RS.<?= number_format($o['total_amount'], 0) ?></td>
      <td><span class="status-badge status-<?= e($o['status']) ?>"><?= ucfirst(e($o['status'])) ?></span></td>
      <td style="font-size:12px;"><?= date('d M Y H:i', strtotime($o['created_at'])) ?></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p style="color:var(--muted)">No orders yet.</p>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>