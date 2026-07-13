<?php
if (session_status() === PHP_SESSION_NONE) session_start();
define('BASE_URL', '/expiry_rush/');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

requireRole('admin');

$pageTitle = 'Manage Users — ExpiryRush';
$uid = currentUserId();

/* ── TOGGLE ACTIVE ── */
if (isset($_GET['toggle'])) {
    $tid = (int)$_GET['toggle'];
    if ($tid !== $uid) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?");
        $stmt->bind_param('i', $tid);
        $stmt->execute();
        $stmt->close();
        setFlash('success', 'User status updated.');
    }
    header('Location: users.php');
    exit;
}

/* ── SEARCH ── */
$search = trim($_GET['q'] ?? '');

if ($search) {
    $stmt = $conn->prepare("
        SELECT * FROM users
        WHERE name LIKE ? OR email LIKE ?
        ORDER BY created_at DESC
    ");
    $like = '%' . $search . '%';
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $users = $stmt->get_result();
    $stmt->close();
} else {
    $users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
}

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
  <h1>👥 MANAGE USERS</h1>
</div>

<form method="GET" style="display:flex;gap:10px;margin-bottom:20px;">
  <input type="text" name="q" placeholder="🔍 Search by name or email…"
         value="<?= e($search) ?>"
         style="flex:1;padding:9px 14px;border-radius:8px;background:var(--card);
                border:1px solid var(--border);color:var(--text);font-family:inherit;font-size:14px;">
  <button type="submit" class="btn btn-primary">Search</button>
  <?php if ($search): ?><a href="users.php" class="btn btn-secondary">Clear</a><?php endif; ?>
</form>

<div style="overflow-x:auto;">
<table class="data-table" style="min-width:700px;">
  <thead>
    <tr>
      <th style="white-space:nowrap;">#</th>
      <th style="white-space:nowrap;">Name</th>
      <th style="white-space:nowrap;">Email</th>
      <th style="white-space:nowrap;">Role</th>
      <th style="white-space:nowrap;">Joined</th>
      <th style="white-space:nowrap;">Status</th>
      <th style="white-space:nowrap;">Action</th>
    </tr>
  </thead>
  <tbody>
  <?php if ($users): while ($u = $users->fetch_assoc()): ?>
  <tr>
    <td style="white-space:nowrap;"><?= $u['id'] ?></td>
    <td style="white-space:nowrap;"><?= e($u['name']) ?></td>
    <td style="white-space:nowrap;"><?= e($u['email']) ?></td>
    <td style="white-space:nowrap;"><span class="role-badge role-<?= e($u['role']) ?>"><?= ucfirst(e($u['role'])) ?></span></td>
    <td style="font-size:12px;white-space:nowrap;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
    <td style="white-space:nowrap;">
      <?php if ($u['is_active']): ?>
        <span style="color:var(--green);font-size:13px;">Active</span>
      <?php else: ?>
        <span style="color:var(--red);font-size:13px;">Inactive</span>
      <?php endif; ?>
    </td>
    <td style="white-space:nowrap;">
      <?php if ($u['id'] !== $uid): ?>
      <a href="users.php?toggle=<?= $u['id'] ?>" class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-primary' ?>">
        <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
      </a>
      <?php else: ?>
      <span style="color:var(--muted);font-size:12px;">You</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endwhile; endif; ?>
  </tbody>
</table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>