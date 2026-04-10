<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';
$siteTitle = appSetting('site_title', APP_NAME);
$siteLogo = appSetting('site_logo', '');
$pageTitle = $pageTitle ?? $siteTitle;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/app.css" rel="stylesheet">
</head>
<body class="app-bg d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg app-navbar shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold text-white d-flex align-items-center gap-2" href="<?= BASE_URL ?>/index.php">
            <?php if ($siteLogo !== ''): ?>
                <img src="<?= e($siteLogo) ?>" alt="Logo" class="app-logo">
            <?php endif; ?>
            <span><?= e($siteTitle) ?></span>
        </a>
        <button class="navbar-toggler border-0 text-white" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0 nav-main-links">
                <li class="nav-item"><a class="nav-link text-white-75" href="<?= BASE_URL ?>/index.php"><i class="bi bi-house-door me-1"></i>Home</a></li>
                <li class="nav-item"><a class="nav-link text-white-75" href="<?= BASE_URL ?>/index.php#track"><i class="bi bi-truck me-1"></i>Track</a></li>
                <li class="nav-item"><a class="nav-link text-white-75" href="<?= BASE_URL ?>/index.php#services"><i class="bi bi-scissors me-1"></i>Services</a></li>
                <li class="nav-item"><a class="nav-link text-white-75" href="<?= BASE_URL ?>/index.php#contact"><i class="bi bi-telephone me-1"></i>Contact</a></li>
            </ul>
            <div class="ms-auto d-flex flex-column flex-lg-row gap-2 mt-3 mt-lg-0 nav-action-group">
                <?php if (isLoggedIn()): ?>
                    <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a>
                    <a class="btn btn-sm btn-danger" href="<?= BASE_URL ?>/logout.php">Logout</a>
                <?php elseif (isCustomerLoggedIn()): ?>
                    <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>/customer_dashboard.php">My Orders</a>
                    <a class="btn btn-sm btn-danger" href="<?= BASE_URL ?>/customer_logout.php">Logout</a>
                <?php else: ?>
                    <a class="btn btn-sm btn-info d-inline-flex align-items-center gap-1" href="<?= BASE_URL ?>/customer_login.php">
                        <i class="bi bi-person"></i><span>Customer Login</span>
                    </a>
                    <a class="btn btn-sm btn-light d-inline-flex align-items-center gap-1" href="<?= BASE_URL ?>/login.php">
                        <i class="bi bi-shield-lock"></i><span>Staff Login</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<?php
$layout = (string)($pageLayout ?? '');
$mainPadding = ($layout === 'admin' || $layout === 'auth') ? 'py-0' : 'py-4';
$mainContainer = ($layout === 'admin') ? 'container-fluid' : 'container';
$mainXPadding = ($layout === 'admin') ? 'px-0' : '';
?>
<main class="<?= $mainContainer ?> <?= $mainPadding ?> <?= $mainXPadding ?> flex-grow-1">

