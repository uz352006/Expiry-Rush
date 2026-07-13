<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
requireRole('customer');
$pageTitle = 'Checkout — ExpiryRush';
$uid = currentUserId();
$stmt = $conn->prepare("
    SELECT c.id AS cart_id, c.locked_price, c.quantity, c.lock_expires_at,
           p.id AS product_id, p.name, p.base_price,
           cat.name AS category_name
    FROM cart c
    JOIN products p ON c.product_id = p.id
    JOIN categories cat ON p.category_id = cat.id
    WHERE c.customer_id = ? AND c.lock_expires_at > NOW()
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
$items = [];
$subtotal = 0;
$origTotal = 0;
while ($r = $result->fetch_assoc()) {
    $subtotal += (float)$r['locked_price'] * (int)$r['quantity'];
    $origTotal += (float)$r['base_price'] * (int)$r['quantity'];
    $items[] = $r;
}
if (empty($items)) {
    setFlash('warning', 'Your cart is empty or price locks have expired.');
    header('Location: cart.php');
    exit;
}
$formData = ['name'=>'','phone'=>'','address'=>'','city'=>'','notes'=>''];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $name = trim($_POST['delivery_name'] ?? '');
    $phone = trim($_POST['delivery_phone'] ?? '');
    $address = trim($_POST['delivery_address'] ?? '');
    $city = trim($_POST['delivery_city'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $formData = compact('name','phone','address','city','notes');
    if (!$name) $errors[] = 'Full name is required.';
    if (!$phone) $errors[] = 'Phone number is required.';
    if (!$address) $errors[] = 'Delivery address is required.';
    if (!$city) $errors[] = 'City is required.';
    if (empty($errors)) {
        try {
            $conn->query("SET @sp_order_id = 0, @sp_error = ''");
            $sp = $conn->prepare("CALL sp_place_order(?, ?, ?, ?, ?, ?, @sp_order_id, @sp_error)");
            $sp->bind_param("isssss", $uid, $name, $phone, $address, $city, $notes);
            $sp->execute();
            $sp->close();
            do {
                if ($res = $conn->store_result()) $res->free();
            } while ($conn->more_results() && $conn->next_result());
            $out = $conn->query("SELECT @sp_order_id AS oid, @sp_error AS err")->fetch_assoc();
            $orderId = (int)$out['oid'];
            $error = trim($out['err']);
            if ($orderId > 0 && $error === '') {
                $_SESSION['cart_count'] = 0;
                setFlash('success', 'Order placed successfully!');
                header("Location: order_confirmation.php?id=" . $orderId);
                exit;
            } else {
                $errors[] = $error ?: 'Checkout failed. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'System error: ' . $e->getMessage();
        }
    }
}
if (empty($formData['name'])) {
    $prof = $conn->prepare("SELECT name, phone, address FROM users WHERE id = ?");
    $prof->bind_param('i', $uid);
    $prof->execute();
    $user = $prof->get_result()->fetch_assoc();
    $prof->close();
    $formData['name'] = $user['name'] ?? '';
    $formData['phone'] = $user['phone'] ?? '';
    $formData['address'] = $user['address'] ?? '';
}
$catEmojis = [
    'Dairy' => '🥛', 'Bakery' => '🍞', 'Fruits' => '🍓',
    'Vegetables' => '🥗', 'Meat' => '🍗', 'Beverages' => '🧃',
    'Snacks' => '🍿', 'Frozen Foods' => '❄️',
];
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
    <h1>🚀 CHECKOUT</h1>
    <p>Review your order and provide delivery details. Cash on Delivery only.</p>
</div>
<?php if (!empty($errors)): ?>
    <div class="flash flash-error">
        <ul>
            <?php foreach($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<div class="checkout-grid" style="display:grid; grid-template-columns: 1.5fr 1fr; gap:24px; align-items: start;">
    <div class="form-card">
        <h3 style="margin-bottom:20px; display:flex; align-items:center; gap:10px;">
            📍 Delivery Details
        </h3>
        <form method="POST" id="checkoutForm">
            <input type="hidden" name="place_order" value="1">
            <div style="margin-bottom:16px;">
                <label class="label">Full Name</label>
                <input type="text" name="delivery_name" placeholder="Receiver's name"
                       value="<?= e($formData['name']) ?>" required>
            </div>
            <div style="margin-bottom:16px;">
                <label class="label">Phone Number</label>
                <input type="text" name="delivery_phone" placeholder="e.g. +92 300 1234567"
                       value="<?= e($formData['phone']) ?>" required>
            </div>
            <div style="margin-bottom:16px;">
                <label class="label">Delivery Address</label>
                <textarea name="delivery_address" rows="3" placeholder="Street, Apartment, Landmark..."
                          required><?= e($formData['address']) ?></textarea>
            </div>
            <div style="margin-bottom:16px;">
                <label class="label">City</label>
                <input type="text" name="delivery_city" placeholder="e.g. Karachi"
                       value="<?= e($formData['city']) ?>" required>
            </div>
            <div style="margin-bottom:24px;">
                <label class="label">Order Notes (Optional)</label>
                <textarea name="notes" rows="2" placeholder="Special instructions for delivery..."><?= e($formData['notes']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-full" id="placeOrderBtn"
                    style="padding:16px; font-size:16px; font-weight:800;">
                CONFIRM & PLACE ORDER — RS.<?= number_format($subtotal, 0) ?>
            </button>
            <p style="text-align:center; margin-top:12px; font-size:12px; color:var(--muted);">
                By placing this order, you agree to pay for it via Cash on Delivery.
            </p>
        </form>
    </div>
    <div class="summary-box" style="position: sticky; top: 100px;">
        <h3 style="margin-bottom:16px;">📋 Order Summary</h3>
        <div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px;">
            <?php foreach ($items as $item):
                $emoji = $catEmojis[$item['category_name']] ?? '🛒';
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--border);">
                <div>
                    <div style="font-weight:600; font-size:14px;">
                        <?= $emoji ?> <?= e($item['name']) ?>
                    </div>
                    <div style="font-size:12px; color:var(--muted);">
                        Qty: <?= $item['quantity'] ?> × RS.<?= number_format($item['locked_price'], 0) ?>
                    </div>
                </div>
                <div style="font-weight:700; color:var(--text);">
                    RS.<?= number_format($item['locked_price'] * $item['quantity'], 0) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="summary-row">
            <span style="color:var(--muted);">Original Total</span>
            <span>RS.<?= number_format($origTotal, 0) ?></span>
        </div>
        <div class="summary-row">
            <span style="color:var(--green);">Price Lock Savings</span>
            <span style="color:var(--green);">-RS.<?= number_format($origTotal - $subtotal, 0) ?></span>
        </div>
        <div class="summary-row" style="border-top:1px solid var(--border); padding-top:12px; margin-top:8px;">
            <span style="font-size:16px; font-weight:800;">Total Payable</span>
            <span style="font-size:20px; font-weight:900; color:var(--orange);">
                RS.<?= number_format($subtotal, 0) ?>
            </span>
        </div>
        <div style="margin-top:16px; padding:12px; background:rgba(34, 197, 94, 0.1); border:1px solid var(--green); border-radius:8px; display:flex; gap:10px;">
            <div style="font-size:20px;">🚚</div>
            <div style="font-size:12px; color:var(--text);">
                <strong>Free Delivery</strong><br>
                Cash on Delivery only. Est: 3-5 days.
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    var btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> PROCESSING ORDER...';
    btn.style.opacity = '0.7';
    btn.style.cursor = 'not-allowed';
});
</script>
<style>
.spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 3px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
    margin-right: 10px;
    vertical-align: middle;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
@media (max-width: 768px) {
    .checkout-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>
<?php require_once __DIR__ . '/footer.php'; ?>