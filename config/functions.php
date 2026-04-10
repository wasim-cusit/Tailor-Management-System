<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function generateCode(string $prefix): string
{
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function paymentMethodOptions(bool $includeLegacy = false): array
{
    $methods = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'easypaisa' => 'Easypaisa',
        'jazzcash' => 'JazzCash',
        'card' => 'Card',
        'other' => 'Other',
    ];

    if ($includeLegacy) {
        $methods += [
            'advance' => 'Advance (Legacy)',
            'partial' => 'Partial (Legacy)',
            'final' => 'Final (Legacy)',
        ];
    }

    return $methods;
}

function normalizePaymentType(string $value, bool $allowLegacy = false): string
{
    $value = strtolower(trim($value));
    $allowed = array_keys(paymentMethodOptions($allowLegacy));
    return in_array($value, $allowed, true) ? $value : 'cash';
}

function paymentTypeLabel(string $value): string
{
    $value = strtolower(trim($value));
    $labels = paymentMethodOptions(true);
    return $labels[$value] ?? ucfirst($value);
}

function recalculateOrder(int $orderId): void
{
    $stmt = db()->prepare('SELECT total_amount, advance_amount FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return;
    }

    $payStmt = db()->prepare(
        'SELECT
            COALESCE(SUM(amount), 0) AS paid,
            COALESCE(SUM(CASE WHEN payment_type = "advance" THEN amount ELSE 0 END), 0) AS advance_paid
         FROM payments
         WHERE order_id = ?'
    );
    $payStmt->execute([$orderId]);
    $pay = $payStmt->fetch() ?: ['paid' => 0, 'advance_paid' => 0];
    $paid = (float)($pay['paid'] ?? 0);

    // Legacy-safe: if old orders stored advance in orders table but no advance payment row exists,
    // include only the missing difference to avoid double counting.
    $missingAdvance = max(0, (float)$order['advance_amount'] - (float)($pay['advance_paid'] ?? 0));
    $paid += $missingAdvance;

    $balance = max(0, (float)$order['total_amount'] - $paid);
    $upd = db()->prepare('UPDATE orders SET paid_amount = ?, balance_amount = ? WHERE id = ?');
    $upd->execute([$paid, $balance, $orderId]);
}

function appSetting(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) {
            return $default;
        }
        return (string)$val;
    } catch (Throwable $e) {
        return $default;
    }
}

function setAppSetting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function availablePermissions(): array
{
    return [
        'view_dashboard' => 'View Dashboard',
        'manage_customers' => 'Manage Customers',
        'manage_orders' => 'Manage Orders',
        'manage_payments' => 'Manage Payments',
        'manage_expenses' => 'Manage Expenses',
        'view_reports' => 'View Reports',
        'manage_settings' => 'Manage Settings',
        'manage_users' => 'Manage Users',
        'manage_roles' => 'Manage Roles',
    ];
}

function ensureUserRoleTables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_code VARCHAR(40) NOT NULL UNIQUE,
                role_name VARCHAR(80) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS role_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_id INT NOT NULL,
                permission_key VARCHAR(60) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_role_permission (role_id, permission_key),
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
            )'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS user_roles (
                user_id INT NOT NULL PRIMARY KEY,
                role_id INT NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
            )'
        );

        db()->prepare(
            "INSERT INTO roles (role_code, role_name, is_system)
             SELECT 'admin', 'Administrator', 1
             WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_code = 'admin')"
        )->execute();
        db()->prepare(
            "INSERT INTO roles (role_code, role_name, is_system)
             SELECT 'staff', 'Staff', 1
             WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_code = 'staff')"
        )->execute();

        $adminId = (int)db()->query("SELECT id FROM roles WHERE role_code = 'admin' LIMIT 1")->fetchColumn();
        $staffId = (int)db()->query("SELECT id FROM roles WHERE role_code = 'staff' LIMIT 1")->fetchColumn();
        if ($adminId > 0 && $staffId > 0) {
            $allPerms = array_keys(availablePermissions());
            foreach ($allPerms as $perm) {
                db()->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$adminId, $perm]);
            }
            foreach (['view_dashboard', 'manage_customers', 'manage_orders', 'manage_payments', 'manage_expenses', 'view_reports'] as $perm) {
                db()->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$staffId, $perm]);
            }
        }

        $users = db()->query('SELECT id, role FROM users')->fetchAll();
        foreach ($users as $u) {
            $uid = (int)$u['id'];
            $legacy = strtolower((string)$u['role']);
            $roleCode = $legacy === 'admin' ? 'admin' : 'staff';
            $stmt = db()->prepare('SELECT id FROM roles WHERE role_code = ? LIMIT 1');
            $stmt->execute([$roleCode]);
            $roleId = (int)$stmt->fetchColumn();
            if ($uid > 0 && $roleId > 0) {
                db()->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$uid, $roleId]);
            }
        }
    } catch (Throwable $e) {
        // Keep app usable if RBAC setup fails.
    }
}

function currentUserRoleCode(): string
{
    return strtolower((string)($_SESSION['role_code'] ?? $_SESSION['role'] ?? 'staff'));
}

function currentUserPermissions(): array
{
    $raw = $_SESSION['permissions'] ?? [];
    return is_array($raw) ? $raw : [];
}

function hasPermission(string $permission): bool
{
    $roleCode = currentUserRoleCode();
    if ($roleCode === 'admin') {
        return true;
    }
    return in_array($permission, currentUserPermissions(), true);
}

function requirePermission(string $permission): void
{
    requireLogin();
    if (!hasPermission($permission)) {
        flash('error', 'You do not have permission to access that page.');
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }
}

function ensureOrderQuantityColumn(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $stmt = db()->query("SHOW COLUMNS FROM orders LIKE 'quantity'");
        $exists = $stmt ? $stmt->fetch() : false;
        if (!$exists) {
            db()->exec("ALTER TABLE orders ADD COLUMN quantity INT NOT NULL DEFAULT 1 AFTER customer_id");
        }
    } catch (Throwable $e) {
        // If migration check fails, keep app usable with existing schema.
    }
}

function ensureAdvancePaymentsBackfill(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        ensurePaymentTypeEnum();
        db()->prepare(
            'INSERT INTO payments (order_id, customer_id, amount, payment_type, payment_date, notes, created_by)
             SELECT
                o.id,
                o.customer_id,
                o.advance_amount,
                "advance",
                DATE(o.created_at),
                "Advance received at order time",
                o.created_by
             FROM orders o
             WHERE o.advance_amount > 0
               AND NOT EXISTS (
                    SELECT 1
                    FROM payments p
                    WHERE p.order_id = o.id
                      AND p.payment_type = "advance"
               )'
        )->execute();

        $idsStmt = db()->query('SELECT id FROM orders');
        $ids = $idsStmt ? $idsStmt->fetchAll() : [];
        foreach ($ids as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                recalculateOrder($id);
            }
        }
    } catch (Throwable $e) {
        // Keep app usable if backfill fails.
    }
}

function ensurePaymentTypeEnum(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db()->exec("ALTER TABLE payments MODIFY payment_type ENUM('cash','bank','easypaisa','jazzcash','card','other','advance','partial','final') NOT NULL DEFAULT 'cash'");
    } catch (Throwable $e) {
        // Keep app usable if schema update fails.
    }
}

