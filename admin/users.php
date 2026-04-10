<?php
require_once __DIR__ . '/../config/functions.php';
ensureUserRoleTables();
requirePermission('manage_users');

$roles = db()->query('SELECT id, role_name, role_code FROM roles ORDER BY role_name ASC')->fetchAll();
$roleById = [];
foreach ($roles as $r) {
    $roleById[(int)$r['id']] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_user') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($fullName === '' || $username === '' || strlen($password) < 6 || !isset($roleById[$roleId])) {
            flash('error', 'Please provide valid user details (password min 6 chars).');
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
        try {
            $legacyRole = (($roleById[$roleId]['role_code'] ?? 'staff') === 'admin') ? 'admin' : 'staff';
            db()->prepare('INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$fullName, $username, password_hash($password, PASSWORD_DEFAULT), $legacyRole]);
            $uid = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$uid, $roleId]);
            flash('success', 'User created successfully.');
        } catch (Throwable $e) {
            flash('error', 'Failed to create user. Username may already exist.');
        }
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit;
    }

    if ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($userId <= 0 || $fullName === '' || $username === '' || !isset($roleById[$roleId])) {
            flash('error', 'Invalid user update request.');
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
        $legacyRole = (($roleById[$roleId]['role_code'] ?? 'staff') === 'admin') ? 'admin' : 'staff';
        db()->prepare('UPDATE users SET full_name = ?, username = ?, role = ? WHERE id = ?')
            ->execute([$fullName, $username, $legacyRole, $userId]);
        db()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)')
            ->execute([$userId, $roleId]);
        flash('success', 'User updated.');
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit;
    }

    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        if ($userId <= 0 || strlen($newPassword) < 6) {
            flash('error', 'Password must be at least 6 characters.');
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        flash('success', 'Password reset successfully.');
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit;
    }

    if ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0 || $userId === (int)($_SESSION['user_id'] ?? 0)) {
            flash('error', 'You cannot delete this user.');
            header('Location: ' . BASE_URL . '/admin/users.php');
            exit;
        }
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        flash('success', 'User deleted.');
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit;
    }
}

$users = db()->query(
    'SELECT u.id, u.full_name, u.username, u.role, u.created_at, r.role_name, r.role_code, r.id AS role_id
     FROM users u
     LEFT JOIN user_roles ur ON ur.user_id = u.id
     LEFT JOIN roles r ON r.id = ur.role_id
     ORDER BY u.id DESC'
)->fetchAll();

$pageTitle = 'User Management';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">User Management</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="bi bi-person-plus me-1"></i>New User</button>
</div>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm align-middle mb-0">
                <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Created</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $roleName = (string)($u['role_name'] ?: ucfirst((string)$u['role'])); ?>
                    <tr>
                        <td><?= e($u['full_name']) ?><?= (int)$u['id'] === (int)($_SESSION['user_id'] ?? 0) ? ' <span class="badge text-bg-secondary ms-1">You</span>' : '' ?></td>
                        <td><code><?= e($u['username']) ?></code></td>
                        <td><?= e($roleName) ?></td>
                        <td><?= e((string)$u['created_at']) ?></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary edit-user-btn" data-bs-toggle="modal" data-bs-target="#editUserModal" data-user='<?= e(json_encode(['id' => (int)$u['id'], 'full_name' => (string)$u['full_name'], 'username' => (string)$u['username'], 'role_id' => (int)($u['role_id'] ?? 0)], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)) ?>'><i class="bi bi-pencil-square"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-warning reset-pass-btn" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-user-id="<?= (int)$u['id'] ?>" data-user-name="<?= e($u['full_name']) ?>"><i class="bi bi-key"></i></button>
                            <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this user?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="5" class="text-center text-muted py-3">No users found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Create User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post"><div class="modal-body row g-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create_user">
        <div class="col-12"><label class="form-label">Full Name</label><input class="form-control" name="full_name" required></div>
        <div class="col-12"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
        <div class="col-12"><label class="form-label">Password</label><input class="form-control" name="password" type="password" minlength="6" required></div>
        <div class="col-12"><label class="form-label">Role</label><select class="form-select" name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div>
    </div><div class="modal-footer"><button class="btn btn-primary">Create User</button></div></form></div></div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Edit User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post" id="editUserForm"><div class="modal-body row g-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="">
        <div class="col-12"><label class="form-label">Full Name</label><input class="form-control" name="full_name" required></div>
        <div class="col-12"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
        <div class="col-12"><label class="form-label">Role</label><select class="form-select" name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div>
    </div><div class="modal-footer"><button class="btn btn-primary">Save Changes</button></div></form></div></div>
</div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Reset Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post" id="resetPasswordForm"><div class="modal-body row g-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="">
        <div class="col-12"><div class="small text-muted" id="resetPasswordTarget"></div></div>
        <div class="col-12"><label class="form-label">New Password</label><input class="form-control" name="new_password" type="password" minlength="6" required></div>
    </div><div class="modal-footer"><button class="btn btn-primary">Reset Password</button></div></form></div></div>
</div>

<script>
document.addEventListener('click', function (e) {
    const editBtn = e.target.closest('.edit-user-btn');
    if (editBtn) {
        const raw = editBtn.getAttribute('data-user');
        if (!raw) return;
        let user = null;
        try { user = JSON.parse(raw); } catch (_) { return; }
        const form = document.getElementById('editUserForm');
        if (!form || !user) return;
        form.querySelector('[name="user_id"]').value = user.id ?? '';
        form.querySelector('[name="full_name"]').value = user.full_name ?? '';
        form.querySelector('[name="username"]').value = user.username ?? '';
        form.querySelector('[name="role_id"]').value = user.role_id ?? '';
        return;
    }

    const resetBtn = e.target.closest('.reset-pass-btn');
    if (resetBtn) {
        const form = document.getElementById('resetPasswordForm');
        if (!form) return;
        form.querySelector('[name="user_id"]').value = resetBtn.getAttribute('data-user-id') || '';
        const target = document.getElementById('resetPasswordTarget');
        if (target) {
            target.textContent = 'User: ' + (resetBtn.getAttribute('data-user-name') || '');
        }
    }
});
</script>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>
