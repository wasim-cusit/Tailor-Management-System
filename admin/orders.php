<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensureOrderQuantityColumn();
ensurePaymentTypeEnum();
ensureAdvancePaymentsBackfill();

$statuses = ['Order', 'Cutting', 'Stitching', 'Ready', 'Delivered'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/admin/orders.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_order') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $deliveryDate = $_POST['delivery_date'] ?? date('Y-m-d');
        $total = (float)($_POST['total_amount'] ?? 0);
        $advance = (float)($_POST['advance_amount'] ?? 0);
        $instructions = trim($_POST['special_instructions'] ?? '');

        $stmt = db()->prepare(
            'INSERT INTO orders (order_code, tracking_code, customer_id, quantity, delivery_date, total_amount, advance_amount, paid_amount, balance_amount, special_instructions, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $orderCode = generateCode('ORD');
        $tracking = generateCode('TRK');
        $paid = $advance;
        $balance = max(0, $total - $paid);
        $stmt->execute([$orderCode, $tracking, $customerId, $quantity, $deliveryDate, $total, $advance, $paid, $balance, $instructions, $_SESSION['user_id']]);
        $orderId = (int)db()->lastInsertId();

        db()->prepare('INSERT INTO order_status_history (order_id, old_status, new_status, changed_by) VALUES (?, NULL, ?, ?)')
            ->execute([$orderId, 'Order', $_SESSION['user_id']]);

        db()->prepare(
            'INSERT INTO kameez_measurements (order_id, length, shoulder, chest, waist, hip, sleeve_length, arm_round, cuff, neck) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orderId, $_POST['k_length'] ?: null, $_POST['k_shoulder'] ?: null, $_POST['k_chest'] ?: null, $_POST['k_waist'] ?: null,
            $_POST['k_hip'] ?: null, $_POST['k_sleeve_length'] ?: null, $_POST['k_arm_round'] ?: null, $_POST['k_cuff'] ?: null, $_POST['k_neck'] ?: null
        ]);

        db()->prepare(
            'INSERT INTO shalwar_measurements (order_id, length, waist, hip, thigh, knee, bottom) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orderId, $_POST['s_length'] ?: null, $_POST['s_waist'] ?: null, $_POST['s_hip'] ?: null, $_POST['s_thigh'] ?: null, $_POST['s_knee'] ?: null, $_POST['s_bottom'] ?: null
        ]);

        db()->prepare(
            'INSERT INTO style_options (order_id, collar_type, pocket, cuff_style, front_style, special_instructions) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $_POST['collar_type'] ?? null, $_POST['pocket'] ?? null, $_POST['cuff_style'] ?? null, $_POST['front_style'] ?? null, $_POST['style_instructions'] ?? null]);

        db()->prepare('INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$customerId, $orderId, 'debit', $total, $orderCode, 'Order billed', date('Y-m-d')]);

        if ($advance > 0) {
            db()->prepare(
                'INSERT INTO payments (order_id, customer_id, amount, payment_type, payment_date, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$orderId, $customerId, $advance, 'advance', date('Y-m-d'), 'Advance received at order time', $_SESSION['user_id']]);
            db()->prepare('INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$customerId, $orderId, 'credit', $advance, $orderCode, 'Advance received', date('Y-m-d')]);
        }

        flash('success', "Order created. Tracking ID: {$tracking}");
    }

    if ($action === 'update_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'Order';
        if (in_array($newStatus, $statuses, true)) {
            $old = db()->prepare('SELECT current_status FROM orders WHERE id = ?');
            $old->execute([$orderId]);
            $oldStatus = $old->fetchColumn();
            db()->prepare('UPDATE orders SET current_status = ? WHERE id = ?')->execute([$newStatus, $orderId]);
            db()->prepare('INSERT INTO order_status_history (order_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?)')
                ->execute([$orderId, $oldStatus ?: null, $newStatus, $_SESSION['user_id']]);
            flash('success', 'Order status updated.');
        }
    }

    if ($action === 'add_payment') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentType = normalizePaymentType((string)($_POST['payment_type'] ?? 'cash'));
        if ($amount > 0) {
            $orderStmt = db()->prepare('SELECT customer_id, order_code FROM orders WHERE id = ?');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if ($order) {
                db()->prepare('INSERT INTO payments (order_id, customer_id, amount, payment_type, payment_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$orderId, $order['customer_id'], $amount, $paymentType, date('Y-m-d'), trim($_POST['notes'] ?? ''), $_SESSION['user_id']]);
                db()->prepare('INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$order['customer_id'], $orderId, 'credit', $amount, $order['order_code'], 'Payment received', date('Y-m-d')]);
                recalculateOrder($orderId);
                flash('success', 'Payment posted.');
            }
        }
    }

    if ($action === 'delete_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            db()->prepare('DELETE FROM ledger_entries WHERE order_id = ?')->execute([$orderId]);
            db()->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
            flash('success', 'Order deleted successfully.');
        }
    }

    if ($action === 'update_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $quantity = max(1, (int)($_POST['quantity'] ?? 1));
            $deliveryDate = (string)($_POST['delivery_date'] ?? date('Y-m-d'));
            $total = (float)($_POST['total_amount'] ?? 0);
            $advance = (float)($_POST['advance_amount'] ?? 0);
            $instructions = trim((string)($_POST['special_instructions'] ?? ''));

            db()->prepare(
                'UPDATE orders
                 SET customer_id = ?, quantity = ?, delivery_date = ?, total_amount = ?, advance_amount = ?, special_instructions = ?
                 WHERE id = ?'
            )->execute([$customerId, $quantity, $deliveryDate, $total, $advance, $instructions, $orderId]);

            $kExistsStmt = db()->prepare('SELECT id FROM kameez_measurements WHERE order_id = ? LIMIT 1');
            $kExistsStmt->execute([$orderId]);
            $kExists = (bool)$kExistsStmt->fetchColumn();
            if ($kExists) {
                db()->prepare(
                    'UPDATE kameez_measurements
                     SET length = ?, shoulder = ?, chest = ?, waist = ?, hip = ?, sleeve_length = ?, arm_round = ?, cuff = ?, neck = ?
                     WHERE order_id = ?'
                )->execute([
                    $_POST['k_length'] ?: null, $_POST['k_shoulder'] ?: null, $_POST['k_chest'] ?: null, $_POST['k_waist'] ?: null,
                    $_POST['k_hip'] ?: null, $_POST['k_sleeve_length'] ?: null, $_POST['k_arm_round'] ?: null, $_POST['k_cuff'] ?: null,
                    $_POST['k_neck'] ?: null, $orderId
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO kameez_measurements (order_id, length, shoulder, chest, waist, hip, sleeve_length, arm_round, cuff, neck)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $orderId, $_POST['k_length'] ?: null, $_POST['k_shoulder'] ?: null, $_POST['k_chest'] ?: null, $_POST['k_waist'] ?: null,
                    $_POST['k_hip'] ?: null, $_POST['k_sleeve_length'] ?: null, $_POST['k_arm_round'] ?: null, $_POST['k_cuff'] ?: null, $_POST['k_neck'] ?: null
                ]);
            }

            $sExistsStmt = db()->prepare('SELECT id FROM shalwar_measurements WHERE order_id = ? LIMIT 1');
            $sExistsStmt->execute([$orderId]);
            $sExists = (bool)$sExistsStmt->fetchColumn();
            if ($sExists) {
                db()->prepare(
                    'UPDATE shalwar_measurements
                     SET length = ?, waist = ?, hip = ?, thigh = ?, knee = ?, bottom = ?
                     WHERE order_id = ?'
                )->execute([
                    $_POST['s_length'] ?: null, $_POST['s_waist'] ?: null, $_POST['s_hip'] ?: null,
                    $_POST['s_thigh'] ?: null, $_POST['s_knee'] ?: null, $_POST['s_bottom'] ?: null, $orderId
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO shalwar_measurements (order_id, length, waist, hip, thigh, knee, bottom)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $orderId, $_POST['s_length'] ?: null, $_POST['s_waist'] ?: null, $_POST['s_hip'] ?: null,
                    $_POST['s_thigh'] ?: null, $_POST['s_knee'] ?: null, $_POST['s_bottom'] ?: null
                ]);
            }

            $styleExistsStmt = db()->prepare('SELECT id FROM style_options WHERE order_id = ? LIMIT 1');
            $styleExistsStmt->execute([$orderId]);
            $styleExists = (bool)$styleExistsStmt->fetchColumn();
            if ($styleExists) {
                db()->prepare(
                    'UPDATE style_options
                     SET collar_type = ?, pocket = ?, cuff_style = ?, front_style = ?, special_instructions = ?
                     WHERE order_id = ?'
                )->execute([
                    trim((string)($_POST['collar_type'] ?? '')) ?: null,
                    trim((string)($_POST['pocket'] ?? '')) ?: null,
                    trim((string)($_POST['cuff_style'] ?? '')) ?: null,
                    trim((string)($_POST['front_style'] ?? '')) ?: null,
                    trim((string)($_POST['style_instructions'] ?? '')) ?: null,
                    $orderId
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO style_options (order_id, collar_type, pocket, cuff_style, front_style, special_instructions)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $orderId,
                    trim((string)($_POST['collar_type'] ?? '')) ?: null,
                    trim((string)($_POST['pocket'] ?? '')) ?: null,
                    trim((string)($_POST['cuff_style'] ?? '')) ?: null,
                    trim((string)($_POST['front_style'] ?? '')) ?: null,
                    trim((string)($_POST['style_instructions'] ?? '')) ?: null
                ]);
            }

            recalculateOrder($orderId);
            flash('success', 'Order updated successfully.');
        }
    }

    header('Location: ' . BASE_URL . '/admin/orders.php');
    exit;
}

$customers = db()->query('SELECT id, customer_code, full_name FROM customers ORDER BY id DESC')->fetchAll();
$q = trim((string)($_GET['q'] ?? ''));
$orderSql = "SELECT o.*, c.full_name AS customer_name, c.phone AS customer_phone,
                    km.length AS k_length, km.shoulder AS k_shoulder, km.chest AS k_chest, km.waist AS k_waist, km.hip AS k_hip, km.sleeve_length AS k_sleeve_length, km.arm_round AS k_arm_round, km.cuff AS k_cuff, km.neck AS k_neck,
                    sm.length AS s_length, sm.waist AS s_waist, sm.hip AS s_hip, sm.thigh AS s_thigh, sm.knee AS s_knee, sm.bottom AS s_bottom,
                    so.collar_type, so.pocket, so.cuff_style, so.front_style, so.special_instructions AS style_instructions
             FROM orders o
             INNER JOIN customers c ON c.id = o.customer_id
             LEFT JOIN kameez_measurements km ON km.order_id = o.id
             LEFT JOIN shalwar_measurements sm ON sm.order_id = o.id
             LEFT JOIN style_options so ON so.order_id = o.id";
$orderParams = [];
if ($q !== '') {
    $orderSql .= " WHERE o.order_code LIKE ? OR o.tracking_code LIKE ? OR c.full_name LIKE ? OR o.current_status LIKE ?";
    $like = "%{$q}%";
    $orderParams = [$like, $like, $like, $like];
}
$orderSql .= " ORDER BY o.id DESC LIMIT 100";
$orderStmt = db()->prepare($orderSql);
$orderStmt->execute($orderParams);
$orders = $orderStmt->fetchAll();

$pageTitle = 'Orders';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="row g-3">
    <div class="col-12">
        <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
    </div>
    <div class="col-xl-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <h2 class="h5 mb-0">Order List</h2>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createOrderModal">
                            <i class="bi bi-plus-lg me-1"></i>Create Order
                        </button>
                        <form method="get" class="d-flex gap-2">
                            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search order, tracking, customer, status...">
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                            <?php if ($q !== ''): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/orders.php"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle">
                        <thead><tr><th>S.No</th><th>Order</th><th>Customer</th><th>Status</th><th>Total</th><th>Balance</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php $i = 1; foreach ($orders as $o): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>/admin/order_details.php?id=<?= (int)$o['id'] ?>" class="fw-semibold text-decoration-none">
                                        <?= e($o['order_code']) ?>
                                    </a>
                                    <br>
                                    <small class="text-muted d-inline-flex align-items-center gap-1">
                                        <span><?= e($o['tracking_code']) ?></span>
                                        <button type="button" class="btn btn-link btn-sm p-0 copy-trk-btn text-decoration-none" data-trk="<?= e($o['tracking_code']) ?>" title="Copy tracking code">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                    </small>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/admin/customer_profile.php?id=<?= (int)$o['customer_id'] ?>" class="text-decoration-none">
                                        <?= e($o['customer_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <form method="post" class="d-flex gap-1">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                        <select name="new_status" class="form-select form-select-sm">
                                            <?php foreach ($statuses as $status): ?>
                                                <option value="<?= e($status) ?>" <?= $status === $o['current_status'] ? 'selected' : '' ?>><?= e($status) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary">Save</button>
                                    </form>
                                </td>
                                <td><?= number_format((float)$o['total_amount'], 0) ?></td>
                                <td><?= number_format((float)$o['balance_amount'], 0) ?></td>
                                <td>
                                    <?php
                                    $waPhone = preg_replace('/\D+/', '', (string)($o['customer_phone'] ?? ''));
                                    if (substr($waPhone, 0, 1) === '0') {
                                        $waPhone = '92' . substr($waPhone, 1);
                                    }
                                    $waText = rawurlencode(
                                        "Assalam o Alaikum " . ($o['customer_name'] ?? '') . "\n"
                                        . "Order: " . ($o['order_code'] ?? '') . "\n"
                                        . "Tracking: " . ($o['tracking_code'] ?? '') . "\n"
                                        . "Status: " . ($o['current_status'] ?? '') . "\n"
                                        . "Delivery: " . ($o['delivery_date'] ?? '') . "\n"
                                        . "Total: Rs " . number_format((float)($o['total_amount'] ?? 0), 0) . "\n"
                                        . "Balance: Rs " . number_format((float)($o['balance_amount'] ?? 0), 0)
                                    );
                                    ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <button
                                            class="btn btn-sm btn-outline-primary edit-order-btn"
                                            type="button"
                                            title="Edit order"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editOrderModal"
                                            data-order='<?= e(json_encode($o, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)) ?>'
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <a class="btn btn-sm btn-outline-success" href="<?= BASE_URL ?>/admin/payments.php?order_id=<?= (int)$o['id'] ?>" title="Add payment">
                                            <i class="bi bi-plus-circle"></i>
                                        </a>
                                        <a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/admin/order_details_pdf.php?id=<?= (int)$o['id'] ?>&view=tailor" target="_blank" rel="noopener" title="Download measurements PDF">
                                            <i class="bi bi-rulers"></i>
                                        </a>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/order_details_pdf.php?id=<?= (int)$o['id'] ?>" target="_blank" rel="noopener" title="Download full PDF">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <?php if ($waPhone !== ''): ?>
                                            <a class="btn btn-sm btn-outline-success" href="https://wa.me/<?= e($waPhone) ?>?text=<?= $waText ?>" target="_blank" rel="noopener" title="Share on WhatsApp">
                                                <i class="bi bi-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('Delete this order? This action cannot be undone.');" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete order">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$orders): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No orders found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade create-order-modal" id="createOrderModal" tabindex="-1" aria-labelledby="createOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="createOrderModalLabel">Create Order</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create_order">

                    <div class="col-12">
                        <div class="create-order-section">
                            <div class="create-order-section-title">Order Information</div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">Customer</label>
                                    <select class="form-select" name="customer_id" required>
                                        <option value="">Select customer</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>"><?= e($c['customer_code'] . ' - ' . $c['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" min="1" step="1" class="form-control" name="quantity" value="1">
                                </div>
                                <div class="col-lg-2">
                                    <label class="form-label">Delivery Date</label>
                                    <input type="date" class="form-control" name="delivery_date" required>
                                </div>
                                <div class="col-lg-2">
                                    <label class="form-label">Total Amount</label>
                                    <input type="number" step="0.01" class="form-control" name="total_amount" data-role="total-amount">
                                </div>
                                <div class="col-lg-2">
                                    <label class="form-label">Advance Amount</label>
                                    <input type="number" step="0.01" class="form-control" name="advance_amount" data-role="advance-amount">
                                </div>
                                <div class="col-lg-4">
                                    <label class="form-label">Remaining Amount</label>
                                    <input type="text" class="form-control" data-role="remaining-amount" value="0.00" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Style Options (سٹائل اختیارات)</div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">Collar Type (کالر)</label>
                                    <input type="text" class="form-control" name="collar_type">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Pocket (جیب)</label>
                                    <input type="text" class="form-control" name="pocket">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Cuff Style (کف اسٹائل)</label>
                                    <input type="text" class="form-control" name="cuff_style">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Front Style (فرنٹ اسٹائل)</label>
                                    <input type="text" class="form-control" name="front_style">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Kameez Measurements (قمیض پیمائش)</div>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label">Length (لمبائی)</label><input type="number" step="0.01" class="form-control" name="k_length"></div>
                                <div class="col-6"><label class="form-label">Shoulder (کندھا)</label><input type="number" step="0.01" class="form-control" name="k_shoulder"></div>
                                <div class="col-6"><label class="form-label">Chest (سینہ)</label><input type="number" step="0.01" class="form-control" name="k_chest"></div>
                                <div class="col-6"><label class="form-label">Waist (کمر)</label><input type="number" step="0.01" class="form-control" name="k_waist"></div>
                                <div class="col-6"><label class="form-label">Hip (ہِپ)</label><input type="number" step="0.01" class="form-control" name="k_hip"></div>
                                <div class="col-6"><label class="form-label">Sleeve Length (آستین)</label><input type="number" step="0.01" class="form-control" name="k_sleeve_length"></div>
                                <div class="col-6"><label class="form-label">Arm Round (بازو گھیرا)</label><input type="number" step="0.01" class="form-control" name="k_arm_round"></div>
                                <div class="col-6"><label class="form-label">Cuff (کف)</label><input type="number" step="0.01" class="form-control" name="k_cuff"></div>
                                <div class="col-6"><label class="form-label">Neck (گلا)</label><input type="number" step="0.01" class="form-control" name="k_neck"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Shalwar Measurements (شلوار پیمائش)</div>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label">Length (لمبائی)</label><input type="number" step="0.01" class="form-control" name="s_length"></div>
                                <div class="col-6"><label class="form-label">Waist (کمر)</label><input type="number" step="0.01" class="form-control" name="s_waist"></div>
                                <div class="col-6"><label class="form-label">Hip (ہِپ)</label><input type="number" step="0.01" class="form-control" name="s_hip"></div>
                                <div class="col-6"><label class="form-label">Thigh (ران)</label><input type="number" step="0.01" class="form-control" name="s_thigh"></div>
                                <div class="col-6"><label class="form-label">Knee (گھٹنا)</label><input type="number" step="0.01" class="form-control" name="s_knee"></div>
                                <div class="col-6"><label class="form-label">Bottom (پائنچہ)</label><input type="number" step="0.01" class="form-control" name="s_bottom"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Notes (نوٹس)</div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">Design Instructions (ڈیزائن ہدایات)</label>
                                    <textarea class="form-control" name="style_instructions" rows="3"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Special Order Instructions (خصوصی ہدایات)</label>
                                    <textarea class="form-control" name="special_instructions" rows="3"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-grid">
                        <button class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1"></i>Create Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade create-order-modal" id="editOrderModal" tabindex="-1" aria-labelledby="editOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="editOrderModalLabel">Edit Order</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-3" id="editOrderForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="update_order">
                    <input type="hidden" name="order_id" value="">

                    <div class="col-12">
                        <div class="create-order-section">
                            <div class="create-order-section-title">Order Information</div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">Customer</label>
                                    <select class="form-select" name="customer_id" required>
                                        <option value="">Select customer</option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>"><?= e($c['customer_code'] . ' - ' . $c['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-2"><label class="form-label">Quantity</label><input type="number" min="1" step="1" class="form-control" name="quantity"></div>
                                <div class="col-lg-2"><label class="form-label">Delivery Date</label><input type="date" class="form-control" name="delivery_date" required></div>
                                <div class="col-lg-2"><label class="form-label">Total Amount</label><input type="number" step="0.01" class="form-control" name="total_amount" data-role="total-amount"></div>
                                <div class="col-lg-2"><label class="form-label">Advance Amount</label><input type="number" step="0.01" class="form-control" name="advance_amount" data-role="advance-amount"></div>
                                <div class="col-lg-4"><label class="form-label">Remaining Amount</label><input type="text" class="form-control" data-role="remaining-amount" value="0.00" readonly></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Style Options (سٹائل اختیارات)</div>
                            <div class="row g-2">
                                <div class="col-12"><label class="form-label">Collar Type (کالر)</label><input type="text" class="form-control" name="collar_type"></div>
                                <div class="col-12"><label class="form-label">Pocket (جیب)</label><input type="text" class="form-control" name="pocket"></div>
                                <div class="col-12"><label class="form-label">Cuff Style (کف اسٹائل)</label><input type="text" class="form-control" name="cuff_style"></div>
                                <div class="col-12"><label class="form-label">Front Style (فرنٹ اسٹائل)</label><input type="text" class="form-control" name="front_style"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Kameez Measurements (قمیض پیمائش)</div>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label">Length (لمبائی)</label><input type="number" step="0.01" class="form-control" name="k_length"></div>
                                <div class="col-6"><label class="form-label">Shoulder (کندھا)</label><input type="number" step="0.01" class="form-control" name="k_shoulder"></div>
                                <div class="col-6"><label class="form-label">Chest (سینہ)</label><input type="number" step="0.01" class="form-control" name="k_chest"></div>
                                <div class="col-6"><label class="form-label">Waist (کمر)</label><input type="number" step="0.01" class="form-control" name="k_waist"></div>
                                <div class="col-6"><label class="form-label">Hip (ہِپ)</label><input type="number" step="0.01" class="form-control" name="k_hip"></div>
                                <div class="col-6"><label class="form-label">Sleeve Length (آستین)</label><input type="number" step="0.01" class="form-control" name="k_sleeve_length"></div>
                                <div class="col-6"><label class="form-label">Arm Round (بازو گھیرا)</label><input type="number" step="0.01" class="form-control" name="k_arm_round"></div>
                                <div class="col-6"><label class="form-label">Cuff (کف)</label><input type="number" step="0.01" class="form-control" name="k_cuff"></div>
                                <div class="col-6"><label class="form-label">Neck (گلا)</label><input type="number" step="0.01" class="form-control" name="k_neck"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Shalwar Measurements (شلوار پیمائش)</div>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label">Length (لمبائی)</label><input type="number" step="0.01" class="form-control" name="s_length"></div>
                                <div class="col-6"><label class="form-label">Waist (کمر)</label><input type="number" step="0.01" class="form-control" name="s_waist"></div>
                                <div class="col-6"><label class="form-label">Hip (ہِپ)</label><input type="number" step="0.01" class="form-control" name="s_hip"></div>
                                <div class="col-6"><label class="form-label">Thigh (ران)</label><input type="number" step="0.01" class="form-control" name="s_thigh"></div>
                                <div class="col-6"><label class="form-label">Knee (گھٹنا)</label><input type="number" step="0.01" class="form-control" name="s_knee"></div>
                                <div class="col-6"><label class="form-label">Bottom (پائنچہ)</label><input type="number" step="0.01" class="form-control" name="s_bottom"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="create-order-section h-100">
                            <div class="create-order-section-title">Notes (نوٹس)</div>
                            <div class="row g-2">
                                <div class="col-12"><label class="form-label">Design Instructions (ڈیزائن ہدایات)</label><textarea class="form-control" name="style_instructions" rows="3"></textarea></div>
                                <div class="col-12"><label class="form-label">Special Order Instructions (خصوصی ہدایات)</label><textarea class="form-control" name="special_instructions" rows="3"></textarea></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-grid">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-check2-circle me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.copy-trk-btn');
    if (!btn) return;
    e.preventDefault();
    const code = btn.getAttribute('data-trk') || '';
    if (!code) return;
    try {
        await navigator.clipboard.writeText(code);
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = 'bi bi-check2';
            setTimeout(function () { icon.className = 'bi bi-copy'; }, 900);
        }
    } catch (_) {}
});

function bindRemainingCalculator(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    const totalEl = form.querySelector('[data-role="total-amount"]');
    const advanceEl = form.querySelector('[data-role="advance-amount"]');
    const remainingEl = form.querySelector('[data-role="remaining-amount"]');
    if (!totalEl || !advanceEl || !remainingEl) return;

    const updateRemaining = function () {
        const total = parseFloat(totalEl.value || '0') || 0;
        const advance = parseFloat(advanceEl.value || '0') || 0;
        const remaining = Math.max(0, total - advance);
        remainingEl.value = remaining.toFixed(2);
    };

    totalEl.addEventListener('input', updateRemaining);
    advanceEl.addEventListener('input', updateRemaining);
    updateRemaining();
}

bindRemainingCalculator('editOrderForm');

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.edit-order-btn');
    if (!btn) return;
    const raw = btn.getAttribute('data-order');
    if (!raw) return;
    let order = null;
    try { order = JSON.parse(raw); } catch (_) { return; }
    if (!order) return;
    const form = document.getElementById('editOrderForm');
    if (!form) return;

    const setVal = function (name, value) {
        const field = form.querySelector('[name="' + name + '"]');
        if (!field) return;
        field.value = value ?? '';
    };

    setVal('order_id', order.id);
    setVal('customer_id', order.customer_id);
    setVal('quantity', order.quantity || 1);
    setVal('delivery_date', order.delivery_date);
    setVal('total_amount', order.total_amount);
    setVal('advance_amount', order.advance_amount);
    setVal('collar_type', order.collar_type);
    setVal('pocket', order.pocket);
    setVal('cuff_style', order.cuff_style);
    setVal('front_style', order.front_style);
    setVal('k_length', order.k_length);
    setVal('k_shoulder', order.k_shoulder);
    setVal('k_chest', order.k_chest);
    setVal('k_waist', order.k_waist);
    setVal('k_hip', order.k_hip);
    setVal('k_sleeve_length', order.k_sleeve_length);
    setVal('k_arm_round', order.k_arm_round);
    setVal('k_cuff', order.k_cuff);
    setVal('k_neck', order.k_neck);
    setVal('s_length', order.s_length);
    setVal('s_waist', order.s_waist);
    setVal('s_hip', order.s_hip);
    setVal('s_thigh', order.s_thigh);
    setVal('s_knee', order.s_knee);
    setVal('s_bottom', order.s_bottom);
    setVal('style_instructions', order.style_instructions);
    setVal('special_instructions', order.special_instructions);

    const totalEl = form.querySelector('[data-role="total-amount"]');
    const advanceEl = form.querySelector('[data-role="advance-amount"]');
    if (totalEl) totalEl.dispatchEvent(new Event('input', { bubbles: true }));
    if (advanceEl) advanceEl.dispatchEvent(new Event('input', { bubbles: true }));
});

const createOrderForm = document.querySelector('#createOrderModal form');
if (createOrderForm && !createOrderForm.id) {
    createOrderForm.id = 'createOrderForm';
}
bindRemainingCalculator('createOrderForm');
</script>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

