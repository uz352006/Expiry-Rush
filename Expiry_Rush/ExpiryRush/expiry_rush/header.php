<?php
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!function_exists('getFlash')) {
    require_once __DIR__ . '/helpers.php';
}
if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}
$role = $_SESSION['role'] ?? '';
$uid = (int)($_SESSION['user_id'] ?? 0);
$uname = $_SESSION['name'] ?? '';
$cartCount = 0;
if ($role === 'customer' && $uid > 0) {
    $ccStmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS total
         FROM   cart
         WHERE  customer_id = ? AND lock_expires_at > NOW()"
    );
    $ccStmt->bind_param('i', $uid);
    $ccStmt->execute();
    $cartCount = (int)$ccStmt->get_result()->fetch_assoc()['total'];
    $ccStmt->close();
    $_SESSION['cart_count'] = $cartCount;
}
$self = $_SERVER['PHP_SELF'];
$_flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'ExpiryRush') ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>style.css">
</head>
<body>
<?php if ($_flash): ?>
<div class="flash flash-<?= e($_flash['type']) ?>"><?= e($_flash['msg']) ?></div>
<?php endif; ?>
<?php if ($uid): ?>
<nav>
  <a href="<?= BASE_URL ?>index.php" class="logo">⚡<span>EXPIRY</span>RUSH</a>
  <div class="nav-links">
    <?php if ($role === 'customer'): ?>
      <a href="<?= BASE_URL ?>browse.php"
         class="nav-btn <?= str_contains($self, 'browse') ? 'active' : '' ?>">🛒 Browse</a>
      <a href="<?= BASE_URL ?>alerts.php"
         class="nav-btn <?= str_contains($self, 'alerts') ? 'active' : '' ?>">🔔 Alerts</a>
      <a href="<?= BASE_URL ?>orders.php"
         class="nav-btn <?= str_contains($self, 'orders') && !str_contains($self, 'confirmation') ? 'active' : '' ?>">📦 Orders</a>
    <?php elseif ($role === 'seller'): ?>
      <a href="<?= BASE_URL ?>dashboard.php"
         class="nav-btn <?= str_contains($self, 'dashboard') ? 'active' : '' ?>">📊 Dashboard</a>
      <a href="<?= BASE_URL ?>products.php"
         class="nav-btn <?= str_contains($self, 'products') ? 'active' : '' ?>">📦 Products</a>
      <a href="<?= BASE_URL ?>seller_orders.php"
         class="nav-btn <?= str_contains($self, 'seller_orders') ? 'active' : '' ?>">🚚 Orders</a>
    <?php elseif ($role === 'admin'): ?>
      <a href="<?= BASE_URL ?>users.php"
         class="nav-btn <?= str_contains($self, 'users') ? 'active' : '' ?>">👥 Users</a>
      <a href="<?= BASE_URL ?>products.php"
         class="nav-btn <?= str_contains($self, 'products') ? 'active' : '' ?>">📦 Products</a>
      <a href="<?= BASE_URL ?>admin_orders.php"
         class="nav-btn <?= str_contains($self, 'admin_orders') ? 'active' : '' ?>">🚚 Orders</a>
      <a href="<?= BASE_URL ?>reports.php"
         class="nav-btn <?= str_contains($self, 'reports') ? 'active' : '' ?>">📊 Reports</a>
    <?php endif; ?>
  </div>
  <div class="nav-right">
    <?php if ($role === 'customer'): ?>
    <a href="<?= BASE_URL ?>cart.php" class="cart-btn">
      🛒 Cart
      <span class="cart-count" id="cartCount"><?= $cartCount ?></span>
    </a>
    <?php endif; ?>
    <span class="user-chip">👋 <?= e($uname) ?></span>
    <a href="<?= BASE_URL ?>logout.php" class="logout-btn">🚪 Logout</a>
  </div>
</nav>
<?php endif; ?>
<?php if ($role === 'customer'): ?>
<script>
(function keepCartFresh() {
    function refresh() {
        fetch('<?= BASE_URL ?>cart_count.php')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var badge = document.getElementById('cartCount');
                if (badge && typeof d.count !== 'undefined') {
                    var prev = parseInt(badge.textContent, 10) || 0;
                    badge.textContent = d.count;
                    if (d.count !== prev) {
                        badge.classList.add('cart-count-update');
                        setTimeout(function () {
                            badge.classList.remove('cart-count-update');
                        }, 400);
                    }
                }
            })
            .catch(function () {});
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh);
    } else {
        refresh();
    }
    setInterval(refresh, 8000);
})();
</script>
<?php endif; ?>
<main class="container">