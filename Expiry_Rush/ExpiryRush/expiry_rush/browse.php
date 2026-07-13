<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('customer');
$pageTitle = 'Browse Deals — ExpiryRush';
$uid = currentUserId();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_alert'])) {
    $pid = (int)$_POST['product_id'];
    $stmt = $conn->prepare("DELETE FROM rush_alerts WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param('ii', $uid, $pid);
    $stmt->execute();
    $stmt->close();
    setFlash('success', 'Alert removed.');
    header('Location: browse.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $pid = (int)$_POST['product_id'];
    $ck = $conn->prepare("
        SELECT id FROM cart
        WHERE customer_id = ? AND product_id = ? AND lock_expires_at > NOW()
    ");
    $ck->bind_param('ii', $uid, $pid);
    $ck->execute();
    $ck->store_result();
    $alreadyIn = ($ck->num_rows > 0);
    $ck->close();
    if ($alreadyIn) {
        setFlash('warning', 'Already in your cart!');
    } else {
        $pr = $conn->prepare("SELECT id, name, current_price, stock FROM active_products WHERE id = ?");
        $pr->bind_param('i', $pid);
        $pr->execute();
        $row = $pr->get_result()->fetch_assoc();
        $pr->close();
        if ($row && (int)$row['stock'] > 0) {
            $lp = (float)$row['current_price'];
            $min = (int)CART_LOCK_MINUTES;
            $stmt = $conn->prepare("
                INSERT INTO cart (customer_id, product_id, quantity, locked_price, lock_expires_at)
                VALUES (?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
                ON DUPLICATE KEY UPDATE
                    locked_price = VALUES(locked_price),
                    lock_expires_at = VALUES(lock_expires_at),
                    quantity = 1
            ");
            $stmt->bind_param('iidi', $uid, $pid, $lp, $min);
            if ($stmt->execute()) {
                setFlash('success', e($row['name']) . ' added to cart at RS. ' . number_format($lp, 0) . '!');
            } else {
                setFlash('error', 'Could not add to cart. Please try again.');
            }
            $stmt->close();
        } else {
            setFlash('error', 'Product not available or out of stock.');
        }
    }
    $qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: browse.php' . $qs);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_alert'])) {
    $pid = (int)$_POST['product_id'];
    $thr = (int)$_POST['threshold'];
    if ($thr >= 10 && $thr <= 90) {
        $stmt = $conn->prepare("
            INSERT INTO rush_alerts (user_id, product_id, target_discount)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE target_discount = VALUES(target_discount), triggered = 0
        ");
        $stmt->bind_param('iii', $uid, $pid, $thr);
        $stmt->execute();
        $stmt->close();
        setFlash('success', 'Rush alert set for ' . $thr . '% off!');
    } else {
        setFlash('error', 'Threshold must be between 10% and 90%.');
    }
    header('Location: browse.php');
    exit;
}
$cat = (int)($_GET['cat'] ?? 0);
$search = trim($_GET['q'] ?? '');
$sort = in_array($_GET['sort'] ?? '', ['discount', 'price']) ? $_GET['sort'] : 'expiry';
$where = 'WHERE 1=1';
$params = [];
$types = '';
if ($cat) {
    $where .= ' AND cat_id = ?';
    $params[] = $cat;
    $types .= 'i';
}
if ($search) {
    $where .= ' AND name LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}
$orderBy = match ($sort) {
    'discount' => 'discount_percent DESC',
    'price' => 'current_price ASC',
    default => 'seconds_left ASC',
};
$sql = "SELECT * FROM active_products $where ORDER BY $orderBy";
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $products = $stmt->get_result();
    $stmt->close();
} else {
    $products = $conn->query($sql);
}
$cats = $conn->query("SELECT * FROM categories ORDER BY name");
$myAlerts = [];
$stmt = $conn->prepare("SELECT product_id, target_discount FROM rush_alerts WHERE user_id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$ar = $stmt->get_result();
$stmt->close();
while ($row = $ar->fetch_assoc()) {
    $myAlerts[$row['product_id']] = $row['target_discount'];
}
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
  <h1>🛒 BROWSE DEALS</h1>
  <p>Prices drop every second — grab them before they expire!</p>
</div>
<form method="GET" action="browse.php"
      style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
  <input type="text" name="q" placeholder="🔍 Search products…"
         value="<?= e($search) ?>"
         style="flex:1;min-width:180px;padding:9px 14px;border-radius:8px;
                background:var(--card);border:1px solid var(--border);
                color:var(--text);font-family:inherit;font-size:14px;">
  <select name="cat" class="filter-select">
    <option value="0">All Categories</option>
    <?php if ($cats) { $cats->data_seek(0); while ($c = $cats->fetch_assoc()): ?>
    <option value="<?= $c['id'] ?>" <?= $cat == $c['id'] ? 'selected' : '' ?>>
      <?= e($c['name']) ?>
    </option>
    <?php endwhile; } ?>
  </select>
  <select name="sort" class="filter-select">
    <option value="expiry"   <?= $sort === 'expiry' ? 'selected' : '' ?>>⏱ Expiring Soon</option>
    <option value="discount" <?= $sort === 'discount' ? 'selected' : '' ?>>🔥 Most Discounted</option>
    <option value="price"    <?= $sort === 'price' ? 'selected' : '' ?>>💰 Lowest Price</option>
  </select>
  <button type="submit" class="btn btn-primary">Search</button>
  <?php if ($search || $cat || $sort !== 'expiry'): ?>
  <a href="browse.php" class="btn btn-secondary">Clear</a>
  <?php endif; ?>
</form>
<?php if (!$products || $products->num_rows === 0): ?>
<div class="empty-state">
  <div class="big">🔍</div>
  <p>No products found<?= $search ? ' for "<strong>' . e($search) . '</strong>"' : '' ?>.</p>
</div>
<?php else: ?>
<div class="grid">
<?php
$catEmojis = [
    'Dairy' => '🥛',
    'Bakery' => '🍞',
    'Fruits' => '🍓',
    'Vegetables' => '🥗',
    'Meat' => '🍗',
    'Beverages' => '🧃',
    'Snacks' => '🍿',
    'Frozen Foods'=> '❄️',
];
while ($p = $products->fetch_assoc()):
    $disc = (int)$p['discount_percent'];
    $cur = (float)$p['current_price'];
    $bar = barWidth($p['listed_at'], $p['expires_at']);
    $bColor = barColor($disc);
    $emoji = $catEmojis[$p['category_name']] ?? '🛒';
    $hasAlert = isset($myAlerts[$p['id']]);
    $qs = http_build_query(array_filter([
        'q' => $search,
        'cat' => $cat ?: null,
        'sort' => $sort !== 'expiry' ? $sort : null,
    ]));
?>
<div class="product-card" data-product-id="<?= $p['id'] ?>">
  <div class="expiry-bar">
    <div class="expiry-fill" style="width:<?= $bar ?>%;background:<?= $bColor ?>;"></div>
  </div>
  <div class="card-top">
    <span class="product-emoji"><?= $emoji ?></span>
    <div class="product-cat"><?= e($p['category_name']) ?></div>
    <div class="product-name"><?= e($p['name']) ?></div>
    <div class="price-row">
      <span class="price-now">RS.<?= number_format($cur, 0) ?></span>
      <span class="price-orig">RS.<?= number_format($p['base_price'], 0) ?></span>
      <span class="disc-badge">-<?= $disc ?>%</span>
    </div>
  </div>
  <div class="card-meta">
    <span>📦 <?= (int)$p['stock'] ?> left</span>
    <span class="<?= timerClass($p['expires_at']) ?>"
          data-expires="<?= strtotime($p['expires_at']) ?>">
      <?= timeLeft($p['expires_at']) ?>
    </span>
  </div>
  <div class="card-actions">
    <form method="POST" action="browse.php<?= $qs ? '?' . $qs : '' ?>" style="flex:1;">
      <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
      <button type="submit" name="add_to_cart" value="1" class="btn-add">🛒 Add to Cart</button>
    </form>
    <?php if ($hasAlert): ?>
      <form method="POST" action="browse.php" style="display:inline;">
        <input type="hidden" name="remove_alert" value="1">
        <input type="hidden" name="product_id"   value="<?= $p['id'] ?>">
        <button type="submit" class="btn-alert active"
                title="Alert at <?= $myAlerts[$p['id']] ?>% — click to remove">🔔</button>
      </form>
    <?php else: ?>
      <button class="btn-alert" title="Set Rush Alert"
              onclick="document.getElementById('alert-modal-<?= $p['id'] ?>').style.display='flex'">🔔</button>
    <?php endif; ?>
  </div>
</div>
<div id="alert-modal-<?= $p['id'] ?>"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);
            z-index:200;align-items:center;justify-content:center;">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:16px;
              padding:28px;width:320px;max-width:90vw;">
    <h3 style="margin-bottom:8px;">🔔 Set Rush Alert</h3>
    <p style="color:var(--muted);font-size:13px;margin-bottom:16px;">
      Notify me when <strong><?= e($p['name']) ?></strong> reaches:
    </p>
    <form method="POST" action="browse.php">
      <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
      <label>TARGET DISCOUNT (%)</label>
      <input type="number" name="threshold" min="10" max="90" value="50" required>
      <div style="display:flex;gap:10px;margin-top:12px;">
        <button type="button" class="btn btn-secondary" style="flex:1;"
                onclick="document.getElementById('alert-modal-<?= $p['id'] ?>').style.display='none'">
          Cancel
        </button>
        <button type="submit" name="set_alert" value="1"
                class="btn btn-primary" style="flex:1;">Set Alert</button>
      </div>
    </form>
  </div>
</div>
<?php endwhile; ?>
</div>
<?php endif; ?>
<script src="<?= BASE_URL ?>app.js"></script>
<script>
(function refreshBadge() {
    fetch('<?= BASE_URL ?>cart_count.php')
        .then(function(r){ return r.json(); })
        .then(function(d){
            var b = document.getElementById('cartCount');
            if (b && d.count !== undefined) {
                b.textContent = d.count;
            }
        })
        .catch(function(){});
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>