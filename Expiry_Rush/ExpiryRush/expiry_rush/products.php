<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('BASE_URL')) define('BASE_URL', '/expiry_rush/');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
requireRole(['seller', 'admin']);
$pageTitle = 'Products — ExpiryRush';
$uid = currentUserId();
$role = currentRole();
$whereOwner = $role === 'admin' ? '1=1' : 'p.seller_id = ' . $uid;
if (isset($_GET['delete'])) {
    $pid = (int)$_GET['delete'];
    if ($role === 'admin') {
        $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
        $stmt->bind_param('i', $pid);
    } else {
        $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ? AND seller_id = ?");
        $stmt->bind_param('ii', $pid, $uid);
    }
    $stmt->execute();
    if ($stmt->affected_rows > 0) setFlash('success', 'Product deactivated.');
    $stmt->close();
    header('Location: products.php');
    exit;
}
$edit = null;
if (isset($_GET['edit'])) {
    $pid = (int)$_GET['edit'];
    if ($role === 'admin') {
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param('i', $pid);
    } else {
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
        $stmt->bind_param('ii', $pid, $uid);
    }
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $name = trim($_POST['name'] ?? '');
    $category_id = (int)$_POST['category_id'];
    $base_price = (float)$_POST['base_price'];
    $stock = (int)$_POST['stock'];
    $expires_at = trim($_POST['expires_at'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $pid = (int)($_POST['product_id'] ?? 0);
    if ($name && $category_id && $base_price > 0 && $stock >= 0 && $expires_at) {
        if ($pid > 0) {
            if ($role === 'admin') {
                $stmt = $conn->prepare("
                    UPDATE products SET name=?, category_id=?, base_price=?, stock=?,
                                       expires_at=?, description=?
                    WHERE id=?
                ");
                $stmt->bind_param('sidissi', $name, $category_id, $base_price, $stock, $expires_at, $description, $pid);
            } else {
                $stmt = $conn->prepare("
                    UPDATE products SET name=?, category_id=?, base_price=?, stock=?,
                                       expires_at=?, description=?
                    WHERE id=? AND seller_id=?
                ");
                $stmt->bind_param('sidissii', $name, $category_id, $base_price, $stock, $expires_at, $description, $pid, $uid);
            }
            $stmt->execute();
            $stmt->close();
            setFlash('success', 'Product updated.');
        } else {
            $seller_id = $role === 'admin' ? (int)($_POST['seller_id'] ?? $uid) : $uid;
            $stmt = $conn->prepare("
                INSERT INTO products (seller_id, category_id, name, description, base_price, stock, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('iissdis', $seller_id, $category_id, $name, $description, $base_price, $stock, $expires_at);
            $stmt->execute();
            $stmt->close();
            setFlash('success', 'Product added.');
        }
        header('Location: products.php');
        exit;
    } else {
        setFlash('error', 'Please fill in all required fields.');
    }
}
$cats = $conn->query("SELECT * FROM categories ORDER BY name");
$products = $conn->query("
    SELECT p.*, c.name AS category_name, u.name AS seller_name
    FROM products p
    JOIN categories c ON p.category_id = c.id
    JOIN users u ON p.seller_id = u.id
    WHERE $whereOwner AND p.is_active = 1
    ORDER BY p.id DESC
");
require_once __DIR__ . '/header.php';
?>
<div class="page-header">
  <h1>📦 <?= $role === 'admin' ? 'ALL PRODUCTS' : 'MY PRODUCTS' ?></h1>
</div>
<div class="form-card" style="margin-bottom:24px;">
  <h2 style="font-size:16px;font-weight:800;margin-bottom:16px;">
    <?= $edit ? '✏️ Edit Product' : '➕ Add New Product' ?>
  </h2>
  <form method="POST" action="products.php">
    <input type="hidden" name="save_product" value="1">
    <?php if ($edit): ?>
    <input type="hidden" name="product_id" value="<?= $edit['id'] ?>">
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div>
        <label>PRODUCT NAME *</label>
        <input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>">
      </div>
      <div>
        <label>CATEGORY *</label>
        <select name="category_id" required>
          <option value="">Select category…</option>
          <?php if ($cats) { $cats->data_seek(0); while ($c = $cats->fetch_assoc()): ?>
          <option value="<?= $c['id'] ?>" <?= ($edit['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?>
          </option>
          <?php endwhile; } ?>
        </select>
      </div>
      <div>
        <label>BASE PRICE (RS.) *</label>
        <input type="number" name="base_price" min="1" step="0.01" required
               value="<?= $edit['base_price'] ?? '' ?>">
      </div>
      <div>
        <label>STOCK *</label>
        <input type="number" name="stock" min="0" required value="<?= $edit['stock'] ?? '' ?>">
      </div>
      <div style="grid-column:1/-1;">
        <label>EXPIRES AT (date &amp; time) *</label>
        <input type="datetime-local" name="expires_at" required
               value="<?= $edit ? date('Y-m-d\TH:i', strtotime($edit['expires_at'])) : '' ?>">
      </div>
      <div style="grid-column:1/-1;">
        <label>DESCRIPTION</label>
        <input type="text" name="description" value="<?= e($edit['description'] ?? '') ?>">
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:12px;">
      <button type="submit" class="btn btn-primary"><?= $edit ? 'Update Product' : 'Add Product' ?></button>
      <?php if ($edit): ?>
      <a href="products.php" class="btn btn-secondary">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php if (!$products || $products->num_rows === 0): ?>
<div class="empty-state">
  <div class="big">📦</div>
  <p>No products yet. Add your first product above!</p>
</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="data-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Name</th>
      <th>Category</th>
      <?php if ($role === 'admin'): ?><th>Seller</th><?php endif; ?>
      <th>Base Price</th>
      <th>Stock</th>
      <th>Expires</th>
      <th>Status</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php while ($p = $products->fetch_assoc()):
    $expired = strtotime($p['expires_at']) < time();
  ?>
  <tr>
    <td><?= $p['id'] ?></td>
    <td><?= e($p['name']) ?></td>
    <td><?= e($p['category_name']) ?></td>
    <?php if ($role === 'admin'): ?><td><?= e($p['seller_name']) ?></td><?php endif; ?>
    <td>RS.<?= number_format($p['base_price'], 0) ?></td>
    <td><?= (int)$p['stock'] ?></td>
    <td style="font-size:12px;"><?= date('d M Y H:i', strtotime($p['expires_at'])) ?></td>
    <td>
      <?php if ($expired): ?>
        <span style="color:var(--red);font-size:12px;">Expired</span>
      <?php elseif ($p['stock'] == 0): ?>
        <span style="color:var(--muted);font-size:12px;">Out of Stock</span>
      <?php else: ?>
        <span style="color:var(--green);font-size:12px;">Active</span>
      <?php endif; ?>
    </td>
    <td style="display:flex;gap:6px;">
      <a href="products.php?edit=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
      <a href="products.php?delete=<?= $p['id'] ?>" class="btn btn-danger btn-sm"
         onclick="return confirm('Deactivate this product?')">Delete</a>
    </td>
  </tr>
  <?php endwhile; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>