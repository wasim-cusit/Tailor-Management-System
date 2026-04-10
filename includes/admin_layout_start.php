<?php
$activeAdminPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<div class="admin-shell">
    <nav class="admin-sidebar admin-sidebar-fixed shadow-sm offcanvas-lg offcanvas-start" id="adminSidebar" tabindex="-1" aria-label="Admin navigation" aria-labelledby="adminSidebarLabel">
        <div class="offcanvas-header d-lg-none">
            <h5 class="offcanvas-title mb-0" id="adminSidebarLabel">Admin Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close menu"></button>
        </div>
        <div class="offcanvas-body p-0">
        <div class="admin-sidebar-header d-flex align-items-center gap-2 mb-3">
            <div class="admin-sidebar-avatar">
                <i class="bi bi-scissors"></i>
            </div>
            <div>
                <div class="admin-sidebar-title">Admin Panel</div>
                <div class="admin-sidebar-subtitle">Tailor Management</div>
            </div>
        </div>

        <div class="admin-sidebar-section-label">Overview</div>
        <a class="admin-link <?= $activeAdminPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/dashboard.php">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>

        <div class="admin-sidebar-section-label mt-2">Work</div>
        <a class="admin-link <?= $activeAdminPage === 'customers.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/customers.php">
            <i class="bi bi-people"></i><span>Customers</span>
        </a>
        <a class="admin-link <?= $activeAdminPage === 'orders.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/orders.php">
            <i class="bi bi-bag-check"></i><span>Orders</span>
        </a>
        <a class="admin-link <?= $activeAdminPage === 'payments.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/payments.php">
            <i class="bi bi-wallet2"></i><span>Payments</span>
        </a>
        <a class="admin-link <?= $activeAdminPage === 'expenses.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/expenses.php">
            <i class="bi bi-cash-coin"></i><span>Expenses</span>
        </a>
        <a class="admin-link <?= $activeAdminPage === 'reports.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/reports.php">
            <i class="bi bi-graph-up"></i><span>Reports</span>
        </a>

        <div class="admin-sidebar-section-label mt-2">System</div>
        <?php if (function_exists('hasPermission') && hasPermission('manage_users')): ?>
        <a class="admin-link <?= $activeAdminPage === 'users.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/users.php">
            <i class="bi bi-people-fill"></i><span>Users</span>
        </a>
        <?php endif; ?>
        <?php if (function_exists('hasPermission') && hasPermission('manage_roles')): ?>
        <a class="admin-link <?= $activeAdminPage === 'roles.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/roles.php">
            <i class="bi bi-shield-lock"></i><span>Roles</span>
        </a>
        <?php endif; ?>
        <a class="admin-link <?= $activeAdminPage === 'settings.php' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/settings.php">
            <i class="bi bi-gear"></i><span>Settings</span>
        </a>
        </div>
    </nav>

    <section class="admin-content-wrap">
        <div class="d-lg-none mb-0">
            <button class="btn btn-primary btn-sm d-inline-flex align-items-center gap-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar">
                <i class="bi bi-list"></i>
                <span>Menu</span>
            </button>
        </div>
        <div class="admin-content">

