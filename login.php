<?php
require_once __DIR__ . '/config/functions.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid session token.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    ensureUserRoleTables();

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $roleCode = strtolower((string)$user['role']);
        if ($roleCode !== 'admin') {
            $roleCode = 'staff';
        }

        $stmtRole = db()->prepare(
            'SELECT r.id, r.role_code, r.role_name
             FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ?
             LIMIT 1'
        );
        $stmtRole->execute([(int)$user['id']]);
        $rbacRole = $stmtRole->fetch();
        if ($rbacRole) {
            $roleCode = strtolower((string)$rbacRole['role_code']);
            $roleName = (string)$rbacRole['role_name'];
            $permStmt = db()->prepare('SELECT permission_key FROM role_permissions WHERE role_id = ?');
            $permStmt->execute([(int)$rbacRole['id']]);
            $permissions = array_values(array_unique(array_map('strval', $permStmt->fetchAll(PDO::FETCH_COLUMN))));
        } else {
            $roleName = ucfirst($roleCode);
            $permissions = [];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['role_code'] = $roleCode;
        $_SESSION['role_name'] = $roleName;
        $_SESSION['permissions'] = $permissions;
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }

    flash('error', 'Invalid credentials.');
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pageTitle = 'Staff Login';
$pageLayout = 'auth';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="text-center mb-3">
                    <?php $logo = appSetting('site_logo', ''); ?>
                    <?php if ($logo !== ''): ?>
                        <img src="<?= e($logo) ?>" class="rounded-3 mb-2" style="height:56px;width:56px;object-fit:cover;" alt="Logo">
                    <?php endif; ?>
                    <div class="fw-semibold"><?= e(appSetting('site_title', APP_NAME)) ?></div>
                </div>
                <h1 class="h4 mb-3 text-center">Staff Login</h1>
                <?php if ($error = flash('error')): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <div class="col-12">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input id="staffPassword" type="password" name="password" class="form-control" required>
                            <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#staffPassword" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100">Login</button>
                    </div>
                </form>
                <p class="small text-muted mt-3 mb-0">Default user: admin / admin123</p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

