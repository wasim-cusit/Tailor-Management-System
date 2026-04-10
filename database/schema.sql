CREATE DATABASE IF NOT EXISTS tailor_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tailor_management;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_name (full_name),
    INDEX idx_customer_phone (phone)
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(30) NOT NULL UNIQUE,
    tracking_code VARCHAR(30) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    delivery_date DATE NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    advance_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    balance_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    current_status ENUM('Order','Cutting','Stitching','Ready','Delivered') NOT NULL DEFAULT 'Order',
    special_instructions TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_status (current_status),
    INDEX idx_delivery_date (delivery_date)
);

CREATE TABLE IF NOT EXISTS kameez_measurements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    length DECIMAL(6,2) NULL,
    shoulder DECIMAL(6,2) NULL,
    chest DECIMAL(6,2) NULL,
    waist DECIMAL(6,2) NULL,
    hip DECIMAL(6,2) NULL,
    sleeve_length DECIMAL(6,2) NULL,
    arm_round DECIMAL(6,2) NULL,
    cuff DECIMAL(6,2) NULL,
    neck DECIMAL(6,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shalwar_measurements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    length DECIMAL(6,2) NULL,
    waist DECIMAL(6,2) NULL,
    hip DECIMAL(6,2) NULL,
    thigh DECIMAL(6,2) NULL,
    knee DECIMAL(6,2) NULL,
    bottom DECIMAL(6,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS style_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    collar_type VARCHAR(80) NULL,
    pocket VARCHAR(80) NULL,
    cuff_style VARCHAR(80) NULL,
    front_style VARCHAR(80) NULL,
    special_instructions TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_type ENUM('cash','bank','easypaisa','jazzcash','card','other','advance','partial','final') NOT NULL DEFAULT 'cash',
    payment_date DATE NOT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payment_date (payment_date)
);

CREATE TABLE IF NOT EXISTS ledger_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    order_id INT NULL,
    entry_type ENUM('debit','credit') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference VARCHAR(120) NULL,
    notes TEXT NULL,
    entry_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_ledger_customer_date (customer_id, entry_date)
);

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    expense_date DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense_date (expense_date)
);

CREATE TABLE IF NOT EXISTS order_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    old_status ENUM('Order','Cutting','Stitching','Ready','Delivered') NULL,
    new_status ENUM('Order','Cutting','Stitching','Ready','Delivered') NOT NULL,
    changed_by INT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(80) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(40) NOT NULL UNIQUE,
    role_name VARCHAR(80) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_key VARCHAR(60) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_role_permission (role_id, permission_key),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL PRIMARY KEY,
    role_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

INSERT INTO app_settings (setting_key, setting_value)
SELECT 'site_title', 'Tailor Management System'
WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'site_title');

INSERT INTO app_settings (setting_key, setting_value)
SELECT 'site_logo', ''
WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'site_logo');

INSERT INTO app_settings (setting_key, setting_value)
SELECT 'contact_phone', '+92 300 0000000'
WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'contact_phone');

INSERT INTO app_settings (setting_key, setting_value)
SELECT 'contact_address', 'Your shop address here'
WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'contact_address');

INSERT INTO users (full_name, username, password_hash, role)
SELECT 'Administrator', 'admin', '$2y$10$cNGrLaux1wHx1LRsq56APe7nmJOxNCvzzarUuvgZqFSNSPuackJ/G', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

INSERT INTO roles (role_code, role_name, is_system)
SELECT 'admin', 'Administrator', 1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_code = 'admin');

INSERT INTO roles (role_code, role_name, is_system)
SELECT 'staff', 'Staff', 1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_code = 'staff');

