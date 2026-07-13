<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('customer');
$pageTitle = 'My Alerts — ExpiryRush';
$uid = currentUserId();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_alert'])) {
    $rid = (int)$_POST['alert_id'];
    $stmt = $conn->prepare("DELETE FROM rush_alerts WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $rid, $uid);
    $stmt->execute();
    $stmt->close();
    setFlash('success', 'Alert removed.');
    header('Location: alerts.php');
    exit;
}
$stmt = $conn->prepare("
    SELECT ra.id, ra.target_discount, ra.triggered,
           p.id AS pid, p.name, p.listed_at, p.expires_at,
           c.name AS category_name
    FROM rush_alerts ra
    JOIN products p   ON ra.product_id  = p.id
    JOIN categories c ON p.category_id  = c.id
    WHERE ra.user_id = ?
    ORDER BY ra.created_at DESC
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$alerts = $stmt->get_result();
$stmt->close();
$catEmojis = ['Dairy'=>'🥛','Bakery'=>'🍞','Fruits'=>'🍓','Vegetables'=>'🥗','Meat'=>'🍗','Beverages'=>'🧃'];
$rows = [];
if ($alerts) {
    while ($r = $alerts->fetch_assoc()) {
        $disc = calcDiscount($r['listed_at'], $r['expires_at']);
        $r['current_discount'] = $disc;
        if ($disc >= $r['target_discount'] && !$r['triggered']) {
            $upd = $conn->prepare("UPDATE rush_alerts SET triggered = 1 WHERE id = ?");
            $upd->bind_param('i', $r['id']);
            $upd->execute();
            $upd->close();
            $r['triggered'] = 1;
        }
        $rows[] = $r;
    }
}
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
  <h1>MY ALERTS</h1>
  <p>You'll be notified here when a product hits your target discount.</p>
</div>
<?php if (empty($rows)): ?>
<div class="empty-state">
  <div class="big">🔔</div>
  <p>No alerts set. <a href="browse.php" style="color:var(--orange);">Browse products</a> and tap 🔔 to set one.</p>
</div>
<?php else: ?>
<?php foreach ($rows as $r):
    $emoji = $catEmojis[$r['category_name']] ?? '🛒';
?>
<div class="alert-card">
  <span style="font-size:32px;"><?= $emoji ?></span>
  <div class="alert-info">
    <div class="alert-name"><?= e($r['name']) ?></div>
    <div class="alert-sub">
      Alert at <?= (int)$r['target_discount'] ?>% off &nbsp;·&nbsp;
      Current: <?= (int)$r['current_discount'] ?>% off &nbsp;·&nbsp;
      <?= timeLeft($r['expires_at']) ?>
    </div>
  </div>
  <div class="<?= $r['triggered'] ? 'triggered' : 'waiting' ?>">
    <?= $r['triggered'] ? '✅ Triggered!' : '⏳ Waiting' ?>
  </div>
  <form method="POST" action="alerts.php" style="display:inline;">
    <input type="hidden" name="remove_alert" value="1">
    <input type="hidden" name="alert_id"     value="<?= $r['id'] ?>">
    <button type="submit" class="btn btn-danger btn-sm"
            onclick="return confirm('Remove this alert?')">Remove</button>
  </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>