<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensureOrderQuantityColumn();

$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    header('Location: ' . BASE_URL . '/admin/orders.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/admin/order_edit.php?id=' . $orderId);
        exit;
    }

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
    header('Location: ' . BASE_URL . '/admin/order_details.php?id=' . $orderId);
    exit;
}

$orderStmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();
if (!$order) {
    flash('error', 'Order not found.');
    header('Location: ' . BASE_URL . '/admin/orders.php');
    exit;
}

$kStmt = db()->prepare('SELECT * FROM kameez_measurements WHERE order_id = ? LIMIT 1');
$kStmt->execute([$orderId]);
$kameez = $kStmt->fetch() ?: [];

$sStmt = db()->prepare('SELECT * FROM shalwar_measurements WHERE order_id = ? LIMIT 1');
$sStmt->execute([$orderId]);
$shalwar = $sStmt->fetch() ?: [];

$styleStmt = db()->prepare('SELECT * FROM style_options WHERE order_id = ? LIMIT 1');
$styleStmt->execute([$orderId]);
$style = $styleStmt->fetch() ?: [];

$customers = db()->query('SELECT id, customer_code, full_name FROM customers ORDER BY id DESC')->fetchAll();

$pageTitle = 'Edit Order';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Edit Order <?= e($order['order_code']) ?></h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/order_details.php?id=<?= (int)$orderId ?>">
        <i class="bi bi-arrow-left me-1"></i>Back to Details
    </a>
</div>

<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">

            <div class="col-12">
                <div class="create-order-section">
                    <div class="create-order-section-title">Order Information</div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Customer</label>
                            <select class="form-select" name="customer_id" required>
                                <option value="">Select customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$order['customer_id'] ? 'selected' : '' ?>>
                                        <?= e($c['customer_code'] . ' - ' . $c['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity</label>
                            <input type="number" min="1" step="1" class="form-control" name="quantity" value="<?= (int)($order['quantity'] ?? 1) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery Date</label>
                            <input type="date" class="form-control" name="delivery_date" value="<?= e((string)$order['delivery_date']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Total Amount</label>
                            <input type="number" step="0.01" class="form-control" name="total_amount" value="<?= e((string)$order['total_amount']) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Advance Amount</label>
                            <input type="number" step="0.01" class="form-control" name="advance_amount" value="<?= e((string)$order['advance_amount']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="create-order-section h-100">
                    <div class="create-order-section-title">Style Options</div>
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label">Collar Type</label><input type="text" class="form-control" name="collar_type" value="<?= e((string)($style['collar_type'] ?? '')) ?>"></div>
                        <div class="col-12"><label class="form-label">Pocket</label><input type="text" class="form-control" name="pocket" value="<?= e((string)($style['pocket'] ?? '')) ?>"></div>
                        <div class="col-12"><label class="form-label">Cuff Style</label><input type="text" class="form-control" name="cuff_style" value="<?= e((string)($style['cuff_style'] ?? '')) ?>"></div>
                        <div class="col-12"><label class="form-label">Front Style</label><input type="text" class="form-control" name="front_style" value="<?= e((string)($style['front_style'] ?? '')) ?>"></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="create-order-section h-100">
                    <div class="create-order-section-title">Kameez Measurements</div>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label">Length</label><input type="number" step="0.01" class="form-control" name="k_length" value="<?= e((string)($kameez['length'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Shoulder</label><input type="number" step="0.01" class="form-control" name="k_shoulder" value="<?= e((string)($kameez['shoulder'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Chest</label><input type="number" step="0.01" class="form-control" name="k_chest" value="<?= e((string)($kameez['chest'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Waist</label><input type="number" step="0.01" class="form-control" name="k_waist" value="<?= e((string)($kameez['waist'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Hip</label><input type="number" step="0.01" class="form-control" name="k_hip" value="<?= e((string)($kameez['hip'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Sleeve Length</label><input type="number" step="0.01" class="form-control" name="k_sleeve_length" value="<?= e((string)($kameez['sleeve_length'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Arm Round</label><input type="number" step="0.01" class="form-control" name="k_arm_round" value="<?= e((string)($kameez['arm_round'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Cuff</label><input type="number" step="0.01" class="form-control" name="k_cuff" value="<?= e((string)($kameez['cuff'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Neck</label><input type="number" step="0.01" class="form-control" name="k_neck" value="<?= e((string)($kameez['neck'] ?? '')) ?>"></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="create-order-section h-100">
                    <div class="create-order-section-title">Shalwar Measurements</div>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label">Length</label><input type="number" step="0.01" class="form-control" name="s_length" value="<?= e((string)($shalwar['length'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Waist</label><input type="number" step="0.01" class="form-control" name="s_waist" value="<?= e((string)($shalwar['waist'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Hip</label><input type="number" step="0.01" class="form-control" name="s_hip" value="<?= e((string)($shalwar['hip'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Thigh</label><input type="number" step="0.01" class="form-control" name="s_thigh" value="<?= e((string)($shalwar['thigh'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Knee</label><input type="number" step="0.01" class="form-control" name="s_knee" value="<?= e((string)($shalwar['knee'] ?? '')) ?>"></div>
                        <div class="col-6"><label class="form-label">Bottom</label><input type="number" step="0.01" class="form-control" name="s_bottom" value="<?= e((string)($shalwar['bottom'] ?? '')) ?>"></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="create-order-section h-100">
                    <div class="create-order-section-title">Notes</div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Design Instructions</label>
                            <textarea class="form-control" name="style_instructions" rows="3"><?= e((string)($style['special_instructions'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Special Order Instructions</label>
                            <textarea class="form-control" name="special_instructions" rows="3"><?= e((string)($order['special_instructions'] ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-grid">
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

