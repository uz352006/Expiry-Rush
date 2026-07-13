<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole('customer');
$pageTitle = 'My Cart — ExpiryRush';
$uid = currentUserId();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_item'])) {
    $rid = (int)$_POST['cart_id'];
    $stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND customer_id = ?");
    $stmt->bind_param('ii', $rid, $uid);
    $stmt->execute();
    $stmt->close();
    setFlash('success', 'Item removed from cart.');
    header('Location: cart.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quantity'])) {
    $cartId = (int)$_POST['cart_id'];
    $quantity = max(1, (int)$_POST['quantity']);
    $st = $conn->prepare("
        SELECT p.stock FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.id = ? AND c.customer_id = ? AND c.lock_expires_at > NOW()
    ");
    $st->bind_param('ii', $cartId, $uid);
    $st->execute();
    $sr = $st->get_result()->fetch_assoc();
    $st->close();
    if ($sr && $quantity <= (int)$sr['stock']) {
        $upd = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND customer_id = ?");
        $upd->bind_param('iii', $quantity, $cartId, $uid);
        $upd->execute();
        $upd->close();
        setFlash('success', 'Quantity updated.');
    } else {
        setFlash('error', 'Quantity exceeds available stock or lock has expired.');
    }
    header('Location: cart.php');
    exit;
}
$stmt = $conn->prepare("
    SELECT c.id AS cart_id, c.locked_price, c.lock_expires_at, c.quantity,
           p.id AS product_id, p.name, p.base_price, p.expires_at, p.stock,
           cat.name AS category_name
    FROM   cart c
    JOIN   products   p   ON c.product_id  = p.id
    JOIN   categories cat ON p.category_id = cat.id
    WHERE  c.customer_id = ?
    ORDER  BY c.id DESC
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
$items = [];
$subtotal = 0;
$origTotal = 0;
$hasExpired = false;
while ($r = $result->fetch_assoc()) {
    $lockSec = strtotime($r['lock_expires_at']) - time();
    $expired = ($lockSec <= 0);
    if ($expired) {
        $hasExpired = true;
    } else {
        $subtotal += (float)$r['locked_price'] * (int)$r['quantity'];
        $origTotal += (float)$r['base_price'] * (int)$r['quantity'];
    }
    $r['lock_sec'] = $lockSec;
    $r['expired'] = $expired;
    $items[] = $r;
}
$cntQ = $conn->prepare("
    SELECT COALESCE(SUM(quantity), 0) AS n
    FROM cart WHERE customer_id = ? AND lock_expires_at > NOW()
");
$cntQ->bind_param('i', $uid);
$cntQ->execute();
$_SESSION['cart_count'] = (int)$cntQ->get_result()->fetch_assoc()['n'];
$cntQ->close();
$catEmojis = [
    'Dairy' => '🥛', 'Bakery' => '🍞', 'Fruits' => '🍓',
    'Vegetables' => '🥗', 'Meat' => '🍗', 'Beverages' => '🧃',
    'Snacks' => '🍿', 'Frozen Foods' => '❄️',
];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <h1>🛒 MY CART</h1>
    <p>Price locks last <?= CART_LOCK_MINUTES ?> minutes. Cash on Delivery only.</p>
</div>
<?php if (empty($items)): ?>
<div class="empty-state">
    <div class="big">🛒</div>
    <p>Your cart is empty.</p>
    <a href="<?= BASE_URL ?>browse.php" class="btn btn-primary" style="margin-top:16px;">Start Shopping →</a>
</div>
<?php else: ?>
<?php foreach ($items as $item):
    $expired = $item['expired'];
    $lockSec = $item['lock_sec'];
    $emoji = $catEmojis[$item['category_name']] ?? '🛒';
    $itemTotal = (float)$item['locked_price'] * (int)$item['quantity'];
    $dimStyle = $expired ? 'opacity:0.5;background:#2a1a1a;' : '';
?>
<div class="cart-item" style="<?= $dimStyle ?>">
    <span class="cart-emoji"><?= $emoji ?></span>
    <div class="cart-info">
        <div class="cart-name"><?= e($item['name']) ?></div>
        <div class="cart-sub">
            RS.<?= number_format($item['locked_price'], 0) ?> each
            &nbsp;·&nbsp; Qty: <?= (int)$item['quantity'] ?>
            &nbsp;·&nbsp;
            <?php if ($expired): ?>
                <span style="color:var(--red);">⚠ Lock expired — remove &amp; re-add</span>
            <?php else: ?>
                <span class="lock-timer"
                      data-lock-expires="<?= strtotime($item['lock_expires_at']) ?>"
                      style="color:var(--green);">
                    ⏱ <?= (int)ceil($lockSec / 60) ?>m lock remaining
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$expired): ?>
    <form method="POST" action="cart.php" style="display:flex;align-items:center;gap:6px;">
        <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
        <input type="hidden" name="update_quantity" value="1">
        <input type="number" name="quantity"
               value="<?= (int)$item['quantity'] ?>"
               min="1" max="<?= (int)$item['stock'] ?>"
               style="width:60px;padding:5px;text-align:center;
                      background:var(--bg);border:1px solid var(--border);
                      border-radius:6px;color:var(--text);">
        <button type="submit" class="btn btn-sm btn-secondary">Update</button>
    </form>
    <?php endif; ?>
    <span class="cart-price" style="<?= $expired
        ? 'text-decoration:line-through;color:var(--muted);'
        : 'min-width:100px;text-align:right;font-weight:800;color:var(--orange);' ?>">
        RS.<?= number_format($itemTotal, 0) ?>
    </span>
    <form method="POST" action="cart.php" style="display:inline;">
        <input type="hidden" name="remove_item" value="1">
        <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Remove this item?')">✕</button>
    </form>
</div>
<?php endforeach; ?>
<div class="summary-box">
    <?php if ($hasExpired): ?>
    <div class="flash flash-warning" style="margin-bottom:12px;">
        ⚠ Some items have expired locks — excluded from total.
        Remove and re-add them to include them.
    </div>
    <?php endif; ?>
    <div class="summary-row">
        <span style="color:var(--muted);">Original Total</span>
        <span>RS.<?= number_format($origTotal, 0) ?></span>
    </div>
    <div class="summary-row">
        <span style="color:var(--green);">You Save</span>
        <span style="color:var(--green);">
            RS.<?= number_format(max(0, $origTotal - $subtotal), 0) ?>
        </span>
    </div>
    <div class="summary-row"
         style="border-top:1px solid var(--border);padding-top:12px;margin-top:4px;">
        <span style="font-size:16px;font-weight:800;">Total</span>
        <span class="summary-total">RS.<?= number_format($subtotal, 0) ?></span>
    </div>
    <div style="margin-top:8px;padding:8px 0;font-size:13px;color:var(--muted);
                border-top:1px solid var(--border);">
        🚚 Cash on Delivery &nbsp;·&nbsp; Delivery in 3–5 business days
    </div>
    <?php $validItems = array_filter($items, fn($i) => !$i['expired']); ?>
    <?php if (!empty($validItems) && $subtotal > 0): ?>
    <a href="<?= BASE_URL ?>checkout.php" class="btn btn-primary btn-full"
       style="margin-top:16px;padding:14px;font-size:16px;text-align:center;">
        Proceed to Checkout →
    </a>
    <?php else: ?>
    <p style="color:var(--muted);font-size:13px;margin-top:12px;text-align:center;">
        <?= $hasExpired
            ? 'Remove expired items above, then re-add them to proceed.'
            : 'Add items to your cart to checkout.' ?>
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>
<script>
(function () {
    var reloaded = false;
    function updateLockTimers() {
        var now = Math.floor(Date.now() / 1000);
        var timers = document.querySelectorAll('.lock-timer');
        var anyExp = false;
        timers.forEach(function (el) {
            var exp = parseInt(el.dataset.lockExpires, 10);
            var left = exp - now;
            if (left <= 0) {
                el.innerHTML = '⚠ Lock expired — remove &amp; re-add';
                el.style.color = 'var(--red)';
                anyExp = true;
            } else {
                var m = Math.floor(left / 60);
                var s = left % 60;
                el.innerHTML = '⏱ ' + m + 'm ' + (s < 10 ? '0' : '') + s + 's lock remaining';
                el.style.color = left < 120 ? 'var(--orange)' : 'var(--green)';
            }
        });
        if (anyExp && !reloaded) {
            reloaded = true;
            setTimeout(function () { location.reload(); }, 2000);
        }
    }
    setInterval(updateLockTimers, 1000);
    updateLockTimers();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>