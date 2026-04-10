<?php
require_once __DIR__ . '/../config/functions.php';
ensureUserRoleTables();
requirePermission('manage_roles');

$permissionMap = availablePermissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/admin/roles.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_role') {
        $roleName = trim((string)($_POST['role_name'] ?? ''));
        $roleCode = strtolower(trim((string)($_POST['role_code'] ?? '')));
        $selectedPerms = $_POST['permissions'] ?? [];
        $selectedPerms = is_array($selectedPerms) ? array_values(array_intersect(array_keys($permissionMap), array_map('strval', $selectedPerms))) : [];

        if ($roleName === '' || $roleCode === '' || !preg_match('/^[a-z0-9_]+$/', $roleCode)) {
            flash('error', 'Role name/code is invalid. Use lowercase letters, numbers, and underscore in code.');
            header('Location: ' . BASE_URL . '/admin/roles.php');
            exit;
        }

        try {
            db()->prepare('INSERT INTO roles (role_code, role_name, is_system) VALUES (?, ?, 0)')->execute([$roleCode, $roleName]);
            $roleId = (int)db()->lastInsertId();
            foreach ($selectedPerms as $perm) {
                db()->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$roleId, $perm]);
            }
            flash('success', 'Role created successfully.');
        } catch (Throwable $e) {
            flash('error', 'Failed to create role. Role code may already exist.');
        }
        header('Location: ' . BASE_URL . '/admin/roles.php');
        exit;
    }

    if ($action === 'update_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $roleName = trim((string)($_POST['role_name'] ?? ''));
        $selectedPerms = $_POST['permissions'] ?? [];
        $selectedPerms = is_array($selectedPerms) ? array_values(array_intersect(array_keys($permissionMap), array_map('strval', $selectedPerms))) : [];
        if ($roleId <= 0 || $roleName === '') {
            flash('error', 'Invalid role update request.');
            header('Location: ' . BASE_URL . '/admin/roles.php');
            exit;
        }

        $rStmt = db()->prepare('SELECT id, role_code, is_system FROM roles WHERE id = ? LIMIT 1');
        $rStmt->execute([$roleId]);
        $role = $rStmt->fetch();
        if (!$role) {
            flash('error', 'Role not found.');
            header('Location: ' . BASE_URL . '/admin/roles.php');
            exit;
        }

        db()->prepare('UPDATE roles SET role_name = ? WHERE id = ?')->execute([$roleName, $roleId]);
        db()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        foreach ($selectedPerms as $perm) {
            db()->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$roleId, $perm]);
        }
        flash('success', 'Role updated.');
        header('Location: ' . BASE_URL . '/admin/roles.php');
        exit;
    }

    if ($action === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        if ($roleId <= 0) {
            flash('error', 'Invalid role delete request.');
            header('Location: ' . BASE_URL . '/admin/roles.php');
            exit;
        }
        $rStmt = db()->prepare('SELECT role_code, is_system FROM roles WHERE id = ? LIMIT 1');
        $rStmt->execute([$roleId]);
        $role = $rStmt->fetch();
        if (!$role) {
            flash('error', 'Role not found.');
            header('Location: ' . BASE_URL . '/admin/roles.php');
            exit;
        }
        if ((int)$role['is_system'] === 1) {
            flash('error', 'System roles cannot be deleted.');
            header('Location: ' . BASE_URL . '/admin/roles.php');
            exit;
        }
        $countStmt = db()->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = ?');
        $countStmt->execute([$roleId]);
        if ((int)$countStmt->fetchColumn() > 0) {
            flash('error', 'Cannot delete this role because users are assigned to it.');
            header('Location: ' . BASE_URL . '/admin/roles.php');
            exit;
        }
        db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
        flash('success', 'Role deleted.');
        header('Location: ' . BASE_URL . '/admin/roles.php');
        exit;
    }
}

$roles = db()->query(
    'SELECT r.*, COUNT(ur.user_id) AS users_count
     FROM roles r
     LEFT JOIN user_roles ur ON ur.role_id = r.id
     GROUP BY r.id
     ORDER BY r.is_system DESC, r.role_name ASC'
)->fetchAll();

$permRows = db()->query('SELECT role_id, permission_key FROM role_permissions')->fetchAll();
$rolePerms = [];
foreach ($permRows as $p) {
    $rid = (int)$p['role_id'];
    $rolePerms[$rid][] = (string)$p['permission_key'];
}

$pageTitle = 'Role Management';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Role Management</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createRoleModal"><i class="bi bi-plus-circle me-1"></i>New Role</button>
</div>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm align-middle mb-0">
                <thead><tr><th>Role</th><th>Code</th><th>Users</th><th>Permissions</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($roles as $r): ?>
                    <?php $perms = $rolePerms[(int)$r['id']] ?? []; ?>
                    <tr>
                        <td><strong><?= e($r['role_name']) ?></strong><?= (int)$r['is_system'] === 1 ? ' <span class="badge text-bg-secondary ms-1">System</span>' : '' ?></td>
                        <td><code><?= e($r['role_code']) ?></code></td>
                        <td><?= (int)$r['users_count'] ?></td>
                        <td class="small text-muted"><?= e(implode(', ', array_map(fn($k) => $permissionMap[$k] ?? $k, $perms))) ?></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary edit-role-btn" data-bs-toggle="modal" data-bs-target="#editRoleModal" data-role='<?= e(json_encode(['id' => (int)$r['id'], 'role_name' => (string)$r['role_name'], 'perms' => $perms], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)) ?>'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <?php if ((int)$r['is_system'] !== 1): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this role?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_role">
                                    <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$roles): ?><tr><td colspan="5" class="text-center text-muted py-3">No roles found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="createRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Create Role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post"><div class="modal-body row g-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create_role">
        <div class="col-md-6"><label class="form-label">Role Name</label><input class="form-control" name="role_name" required></div>
        <div class="col-md-6"><label class="form-label">Role Code</label><input class="form-control" name="role_code" placeholder="e.g. cashier" required></div>
        <div class="col-12"><label class="form-label">Permissions</label><div class="row g-2">
            <?php foreach ($permissionMap as $key => $label): ?>
                <div class="col-md-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($key) ?>"><span class="form-check-label"><?= e($label) ?></span></label></div>
            <?php endforeach; ?>
        </div></div>
    </div><div class="modal-footer"><button class="btn btn-primary">Create Role</button></div></form></div></div>
</div>

<div class="modal fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Edit Role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post" id="editRoleForm"><div class="modal-body row g-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="update_role"><input type="hidden" name="role_id" value="">
        <div class="col-12"><label class="form-label">Role Name</label><input class="form-control" name="role_name" required></div>
        <div class="col-12"><label class="form-label">Permissions</label><div class="row g-2">
            <?php foreach ($permissionMap as $key => $label): ?>
                <div class="col-md-4"><label class="form-check"><input class="form-check-input js-edit-role-perm" type="checkbox" name="permissions[]" value="<?= e($key) ?>"><span class="form-check-label"><?= e($label) ?></span></label></div>
            <?php endforeach; ?>
        </div></div>
    </div><div class="modal-footer"><button class="btn btn-primary">Save Changes</button></div></form></div></div>
</div>

<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.edit-role-btn');
    if (!btn) return;
    const raw = btn.getAttribute('data-role');
    if (!raw) return;
    let role = null;
    try { role = JSON.parse(raw); } catch (_) { return; }
    const form = document.getElementById('editRoleForm');
    if (!form || !role) return;
    form.querySelector('[name="role_id"]').value = role.id ?? '';
    form.querySelector('[name="role_name"]').value = role.role_name ?? '';
    const set = new Set(Array.isArray(role.perms) ? role.perms : []);
    form.querySelectorAll('.js-edit-role-perm').forEach(function (el) {
        el.checked = set.has(el.value);
    });
});
</script>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>
