<?php
if (session_status() === PHP_SESSION_NONE) session_start();
define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    if ($role === 'seller') header('Location: ' . BASE_URL . 'dashboard.php');
    elseif ($role === 'admin') header('Location: ' . BASE_URL . 'users.php');
    else header('Location: ' . BASE_URL . 'browse.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'login') {
        $email = trim($_POST['email'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        $stmt = $conn->prepare("SELECT id, name, role, password, is_active FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user && password_verify($pass, $user['password'])) {
            if (!$user['is_active']) {
                $error = 'Your account has been deactivated.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['cart_count'] = 0;
                if ($user['role'] === 'seller') header('Location: ' . BASE_URL . 'dashboard.php');
                elseif ($user['role'] === 'admin') header('Location: ' . BASE_URL . 'users.php');
                else header('Location: ' . BASE_URL . 'browse.php');
                exit;
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
    if (($_POST['action'] ?? '') === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['customer', 'seller']) ? $_POST['role'] : 'customer';
        $pass = trim($_POST['password'] ?? '');
        if ($name && $email && $pass) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)");
            $stmt->bind_param('ssss', $name, $email, $hash, $role);
            if ($stmt->execute()) {
                $_SESSION['user_id'] = $conn->insert_id;
                $_SESSION['name'] = $name;
                $_SESSION['role'] = $role;
                $_SESSION['cart_count'] = 0;
                if ($role === 'seller') header('Location: ' . BASE_URL . 'dashboard.php');
                else header('Location: ' . BASE_URL . 'browse.php');
                exit;
            } else {
                $error = 'Email already registered.';
            }
        } else {
            $error = 'Please fill all fields.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ExpiryRush — Login</title>
<link rel="stylesheet" href="<?= BASE_URL ?>style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <div class="brand">⚡<span>EXPIRY</span>RUSH</div>
      <p>Beat the Clock. Save the Food.</p>
    </div>
    <?php if ($error): ?>
      <div class="flash flash-error" style="margin-bottom:16px;"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="tabs">
      <button class="tab active" onclick="showTab('login', this)">Login</button>
      <button class="tab" onclick="showTab('register', this)">Register</button>
    </div>
    <div id="tab-login">
      <form method="POST">
        <input type="hidden" name="action" value="login">
        <label>EMAIL</label>
        <input type="email" name="email" id="login-email" required>
        <label>PASSWORD</label>
        <input type="password" name="password" id="login-password" required>
        <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Demo password: <code>password</code></p>
        <div class="demo-row">
          <span class="demo-chip" onclick="fillDemo('customer@expiryrush.com')">👤 Customer</span>
          <span class="demo-chip" onclick="fillDemo('seller@expiryrush.com')">🏪 Seller</span>
          <span class="demo-chip" onclick="fillDemo('admin@expiryrush.com')">🛡 Admin</span>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Login →</button>
      </form>
    </div>
    <div id="tab-register" style="display:none;">
      <form method="POST">
        <input type="hidden" name="action" value="register">
        <label>FULL NAME</label>
        <input type="text" name="name" required>
        <label>EMAIL</label>
        <input type="email" name="email" required>
        <label>ROLE</label>
        <select name="role">
          <option value="customer">Customer</option>
          <option value="seller">Seller</option>
        </select>
        <label>PASSWORD</label>
        <input type="password" name="password" required>
        <button type="submit" class="btn btn-primary btn-full">Create Account →</button>
      </form>
    </div>
  </div>
</div>
<script>
function showTab(id, btn) {
  document.getElementById('tab-login').style.display = id === 'login' ? 'block' : 'none';
  document.getElementById('tab-register').style.display = id === 'register' ? 'block' : 'none';
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
}
function fillDemo(email) {
  document.getElementById('login-email').value = email;
  document.getElementById('login-password').value = 'password';
}
function updateCartCount() {
    if (document.querySelector('.cart-count')) {
        fetch('cart_count.php')
            .then(response => response.json())
            .then(data => {
                let badge = document.querySelector('.cart-count');
                if(badge) {
                    badge.textContent = data.count;
                    if(data.count === 0) {
                        badge.style.opacity = '0.5';
                    } else {
                        badge.style.opacity = '1';
                    }
                }
            })
            .catch(error => console.log('Cart update error:', error));
    }
}
if (document.querySelector('.cart-count')) {
    updateCartCount();
    setInterval(updateCartCount, 5000);
}
</script>
</body>
</html>