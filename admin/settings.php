<?php
require_once __DIR__ . '/../config/functions.php';
ensureUserRoleTables();
requireLogin();
requirePermission('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/admin/settings.php');
        exit;
    }

    $siteTitle = trim($_POST['site_title'] ?? '');
    if ($siteTitle !== '') {
        setAppSetting('site_title', $siteTitle);
    }

    setAppSetting('contact_phone', trim($_POST['contact_phone'] ?? ''));
    setAppSetting('contact_address', trim($_POST['contact_address'] ?? ''));

    if (isset($_FILES['site_logo']) && is_array($_FILES['site_logo'])) {
        $file = $_FILES['site_logo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string)$file['tmp_name'];
            $name = (string)$file['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'webp'];

            if (!in_array($ext, $allowed, true)) {
                flash('error', 'Logo must be png/jpg/jpeg/webp.');
                header('Location: ' . BASE_URL . '/admin/settings.php');
                exit;
            }

            $imgDir = dirname(__DIR__) . '/assets/img';
            if (!is_dir($imgDir)) {
                mkdir($imgDir, 0775, true);
            }

            $targetRel = '/assets/img/logo.' . $ext;
            $targetAbs = dirname(__DIR__) . $targetRel;

            if (!move_uploaded_file($tmp, $targetAbs)) {
                flash('error', 'Failed to upload logo.');
                header('Location: ' . BASE_URL . '/admin/settings.php');
                exit;
            }

            setAppSetting('site_logo', BASE_URL . $targetRel);
        }
    }

    flash('success', 'Settings saved.');
    header('Location: ' . BASE_URL . '/admin/settings.php');
    exit;
}

$pageTitle = 'Settings';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';

$currentTitle = appSetting('site_title', APP_NAME);
$currentLogo = appSetting('site_logo', '');
$currentPhone = appSetting('contact_phone', '');
$currentAddress = appSetting('contact_address', '');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">App Settings</h1>
</div>

<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <div class="col-12">
                <label class="form-label">Site Title</label>
                <input class="form-control" name="site_title" value="<?= e($currentTitle) ?>" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Contact Phone</label>
                <input class="form-control" name="contact_phone" value="<?= e($currentPhone) ?>" placeholder="+92 300 0000000">
            </div>

            <div class="col-md-6">
                <label class="form-label">Contact Address</label>
                <input class="form-control" name="contact_address" value="<?= e($currentAddress) ?>" placeholder="Your shop address">
            </div>

            <div class="col-12">
                <label class="form-label">Logo (png/jpg/webp)</label>
                <input type="file" class="form-control" name="site_logo" accept=".png,.jpg,.jpeg,.webp">
                <?php if ($currentLogo !== ''): ?>
                    <div class="mt-2 d-flex align-items-center gap-2">
                        <img src="<?= e($currentLogo) ?>" alt="Logo" style="height:42px;width:auto;border-radius:10px;">
                        <span class="text-muted small">Current logo</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-12">
                <button class="btn btn-primary">Save Settings</button>
            </div>
        </form>
    </div>
</div>

<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

