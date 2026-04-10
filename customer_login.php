<?php
require_once __DIR__ . '/config/functions.php';

if (isCustomerLoggedIn()) {
    header('Location: ' . BASE_URL . '/customer_dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid session token.');
        header('Location: ' . BASE_URL . '/customer_login.php');
        exit;
    }

    $customerCode = trim($_POST['customer_code'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $stmt = db()->prepare('SELECT id, full_name, customer_code FROM customers WHERE customer_code = ? AND phone = ? LIMIT 1');
    $stmt->execute([$customerCode, $phone]);
    $customer = $stmt->fetch();

    if ($customer) {
        session_regenerate_id(true);
        $_SESSION['customer_id'] = $customer['id'];
        $_SESSION['customer_name'] = $customer['full_name'];
        $_SESSION['customer_code'] = $customer['customer_code'];
        header('Location: ' . BASE_URL . '/customer_dashboard.php');
        exit;
    }

    flash('error', 'Customer login failed. Check code and phone.');
    header('Location: ' . BASE_URL . '/customer_login.php');
    exit;
}

$pageTitle = 'Customer Login';
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
                <h1 class="h4 mb-1 text-center">Customer Login</h1>
                <p class="text-muted small text-center">Use your customer code and registered phone number.</p>
                <?php if ($error = flash('error')): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <div class="col-12">
                        <label class="form-label">Customer Code</label>
                        <input type="text" name="customer_code" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Phone</label>
                        <div class="input-group">
                            <input id="customerPhone" type="text" name="phone" class="form-control" required>
                            <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#customerPhone" aria-label="Show phone">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

