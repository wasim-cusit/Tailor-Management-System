<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensurePaymentTypeEnum();
ensureAdvancePaymentsBackfill();
$paymentMethods = paymentMethodOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . BASE_URL . '/admin/payments.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? 'add_payment');

    if ($action === 'add_payment') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentType = normalizePaymentType((string)($_POST['payment_type'] ?? 'cash'));
        $paymentDate = (string)($_POST['payment_date'] ?? date('Y-m-d'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($orderId <= 0 || $amount <= 0) {
            flash('error', 'Order and amount are required.');
            header('Location: ' . BASE_URL . '/admin/payments.php');
            exit;
        }

        $orderStmt = db()->prepare('SELECT customer_id, order_code FROM orders WHERE id = ?');
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            flash('error', 'Invalid order selected.');
            header('Location: ' . BASE_URL . '/admin/payments.php');
            exit;
        }

        db()->prepare(
            'INSERT INTO payments (order_id, customer_id, amount, payment_type, payment_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $order['customer_id'], $amount, $paymentType, $paymentDate, $notes, $_SESSION['user_id']]);

        db()->prepare(
            'INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$order['customer_id'], $orderId, 'credit', $amount, $order['order_code'], 'Payment received', $paymentDate]);

        recalculateOrder($orderId);
        flash('success', 'Payment posted successfully.');
        header('Location: ' . BASE_URL . '/admin/payments.php?order_id=' . (int)$orderId);
        exit;
    }

    if ($action === 'update_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentType = normalizePaymentType((string)($_POST['payment_type'] ?? 'cash'));
        $paymentDate = (string)($_POST['payment_date'] ?? date('Y-m-d'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($paymentId <= 0 || $orderId <= 0 || $amount <= 0) {
            flash('error', 'Invalid payment update request.');
            header('Location: ' . BASE_URL . '/admin/payments.php');
            exit;
        }

        $oldStmt = db()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $oldStmt->execute([$paymentId]);
        $oldPayment = $oldStmt->fetch();
        if (!$oldPayment) {
            flash('error', 'Payment not found.');
            header('Location: ' . BASE_URL . '/admin/payments.php');
            exit;
        }

        $newOrderStmt = db()->prepare('SELECT id, customer_id, order_code FROM orders WHERE id = ? LIMIT 1');
        $newOrderStmt->execute([$orderId]);
        $newOrder = $newOrderStmt->fetch();
        if (!$newOrder) {
            flash('error', 'Invalid order selected.');
            header('Location: ' . BASE_URL . '/admin/payments.php');
            exit;
        }

        $oldOrderStmt = db()->prepare('SELECT id, customer_id, order_code FROM orders WHERE id = ? LIMIT 1');
        $oldOrderStmt->execute([(int)$oldPayment['order_id']]);
        $oldOrder = $oldOrderStmt->fetch();

        db()->prepare(
            'UPDATE payments
             SET order_id = ?, customer_id = ?, amount = ?, payment_type = ?, payment_date = ?, notes = ?
             WHERE id = ?'
        )->execute([$orderId, $newOrder['customer_id'], $amount, $paymentType, $paymentDate, $notes, $paymentId]);

        if ($oldOrder && (int)$oldPayment['order_id'] !== $orderId) {
            db()->prepare(
                'INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $oldOrder['customer_id'],
                $oldOrder['id'],
                'debit',
                (float)$oldPayment['amount'],
                (string)$oldOrder['order_code'],
                'Payment edit adjustment (reversed old order)',
                $paymentDate
            ]);

            db()->prepare(
                'INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $newOrder['customer_id'],
                $newOrder['id'],
                'credit',
                $amount,
                (string)$newOrder['order_code'],
                'Payment edit adjustment (new order)',
                $paymentDate
            ]);
        } else {
            $delta = $amount - (float)$oldPayment['amount'];
            if (abs($delta) > 0.0001) {
                db()->prepare(
                    'INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $newOrder['customer_id'],
                    $newOrder['id'],
                    $delta > 0 ? 'credit' : 'debit',
                    abs($delta),
                    (string)$newOrder['order_code'],
                    'Payment edit amount adjustment',
                    $paymentDate
                ]);
            }
        }

        recalculateOrder((int)$oldPayment['order_id']);
        if ((int)$oldPayment['order_id'] !== $orderId) {
            recalculateOrder($orderId);
        }

        flash('success', 'Payment updated successfully.');
        header('Location: ' . BASE_URL . '/admin/payments.php?order_id=' . $orderId);
        exit;
    }

    if ($action === 'delete_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId <= 0) {
            flash('error', 'Invalid payment delete request.');
            header('Location: ' . BASE_URL . '/admin/payments.php');
            exit;
        }

        $paymentStmt = db()->prepare(
            'SELECT p.*, o.order_code
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $paymentStmt->execute([$paymentId]);
        $payment = $paymentStmt->fetch();
        if (!$payment) {
            flash('error', 'Payment not found.');
            header('Location: ' . BASE_URL . '/admin/payments.php');
            exit;
        }

        db()->prepare('DELETE FROM payments WHERE id = ?')->execute([$paymentId]);
        db()->prepare(
            'INSERT INTO ledger_entries (customer_id, order_id, entry_type, amount, reference, notes, entry_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $payment['customer_id'],
            $payment['order_id'],
            'debit',
            (float)$payment['amount'],
            (string)$payment['order_code'],
            'Payment deleted adjustment',
            date('Y-m-d')
        ]);
        recalculateOrder((int)$payment['order_id']);
        flash('success', 'Payment deleted successfully.');
        header('Location: ' . BASE_URL . '/admin/payments.php?order_id=' . (int)$payment['order_id']);
        exit;
    }

    header('Location: ' . BASE_URL . '/admin/payments.php');
    exit;
}

$selectedOrderId = (int)($_GET['order_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$ordersStmt = db()->query(
    "SELECT o.id, o.order_code, o.balance_amount, c.full_name AS customer_name
     FROM orders o
     INNER JOIN customers c ON c.id = o.customer_id
     ORDER BY o.id DESC"
);
$orderOptions = $ordersStmt->fetchAll();

$paySql = "SELECT p.*, o.order_code, c.customer_code, c.full_name AS customer_name, u.full_name AS posted_by_name
           FROM payments p
           INNER JOIN orders o ON o.id = p.order_id
           INNER JOIN customers c ON c.id = p.customer_id
           LEFT JOIN users u ON u.id = p.created_by";
$payParams = [];
if ($q !== '') {
    $paySql .= " WHERE o.order_code LIKE ? OR c.full_name LIKE ? OR c.customer_code LIKE ? OR p.payment_type LIKE ?";
    $like = "%{$q}%";
    $payParams = [$like, $like, $like, $like];
}
$paySql .= " ORDER BY p.id DESC LIMIT 300";
$payStmt = db()->prepare($paySql);
$payStmt->execute($payParams);
$payments = $payStmt->fetchAll();

$pageTitle = 'Payments';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="row g-3">
    <div class="col-12">
        <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    </div>
    <div class="col-12">
        <div class="card shadow-sm payments-list-card">
            <div class="card-body payments-list-body">
                <div class="d-flex justify-content-between mb-2">
                    <h2 class="h5 mb-0">Payment List</h2>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                            <i class="bi bi-plus-circle me-1"></i>New Payment
                        </button>
                        <form method="get" class="d-flex gap-2">
                            <?php if ($selectedOrderId > 0): ?>
                                <input type="hidden" name="order_id" value="<?= (int)$selectedOrderId ?>">
                            <?php endif; ?>
                            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search order, customer, code, type...">
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                            <?php if ($q !== '' || $selectedOrderId > 0): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/payments.php"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle">
                        <thead>
                        <tr>
                            <th class="text-nowrap">S.No</th>
                            <th class="text-nowrap">Date</th>
                            <th class="text-nowrap">Order Code</th>
                            <th class="text-nowrap">Customer</th>
                            <th class="text-nowrap">Customer Code</th>
                            <th class="text-nowrap">Payment Method</th>
                            <th class="text-end text-nowrap">Amount</th>
                            <th class="text-nowrap">Posted By</th>
                            <th class="text-center text-nowrap">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php $i = 1; ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= e($p['payment_date']) ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>/admin/order_details.php?id=<?= (int)$p['order_id'] ?>" class="text-decoration-none fw-semibold">
                                        <?= e($p['order_code']) ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/admin/customer_profile.php?id=<?= (int)$p['customer_id'] ?>" class="text-decoration-none fw-semibold">
                                        <?= e($p['customer_name']) ?>
                                    </a>
                                </td>
                                <td class="text-muted"><?= e($p['customer_code']) ?></td>
                                <td><span class="badge text-bg-success-subtle text-success"><?= e(paymentTypeLabel((string)$p['payment_type'])) ?></span></td>
                                <td class="text-end">Rs <?= number_format((float)$p['amount'], 0) ?></td>
                                <td><?= e($p['posted_by_name'] ?? '') ?></td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 flex-wrap">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary edit-payment-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editPaymentModal"
                                            data-payment='<?= e(json_encode($p, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)) ?>'
                                            title="Edit payment"
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_payment">
                                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete payment">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/admin/payment_receipt_thermal.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" title="Thermal print">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/payment_receipt_pdf.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" title="PDF download">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$payments): ?>
                            <tr><td colspan="9" class="text-center text-muted py-3">No payments found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade create-order-modal" id="addPaymentModal" tabindex="-1" aria-labelledby="addPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="addPaymentModalLabel">New Payment</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_payment">
                    <div class="col-12">
                        <label class="form-label mb-1">Order</label>
                        <select class="form-select" name="order_id" required>
                            <option value="">Select order</option>
                            <?php foreach ($orderOptions as $o): ?>
                                <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $selectedOrderId ? 'selected' : '' ?>>
                                    <?= e($o['order_code'] . ' - ' . $o['customer_name'] . ' (Bal: ' . number_format((float)$o['balance_amount'], 0) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-1">Amount</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" placeholder="Amount" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-1">Payment Method</label>
                        <select name="payment_type" class="form-select">
                            <?php foreach ($paymentMethods as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $value === 'cash' ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1">Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1">Notes</label>
                        <textarea class="form-control" name="notes" placeholder="Optional note"></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Post Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="modal fade create-order-modal" id="editPaymentModal" tabindex="-1" aria-labelledby="editPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="editPaymentModalLabel">Edit Payment</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-2" id="editPaymentForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="update_payment">
                    <input type="hidden" name="payment_id" value="">
                    <div class="col-12">
                        <label class="form-label mb-1">Order</label>
                        <select class="form-select" name="order_id" required>
                            <option value="">Select order</option>
                            <?php foreach ($orderOptions as $o): ?>
                                <option value="<?= (int)$o['id'] ?>">
                                    <?= e($o['order_code'] . ' - ' . $o['customer_name'] . ' (Bal: ' . number_format((float)$o['balance_amount'], 0) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-1">Amount</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-1">Payment Method</label>
                        <select name="payment_type" class="form-select">
                            <?php foreach ($paymentMethods as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1">Date</label>
                        <input type="date" class="form-control" name="payment_date" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label mb-1">Notes</label>
                        <textarea class="form-control" name="notes"></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-check2-circle me-1"></i>Update Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.edit-payment-btn');
    if (!btn) return;
    const raw = btn.getAttribute('data-payment');
    if (!raw) return;
    let payment = null;
    try { payment = JSON.parse(raw); } catch (_) { return; }
    if (!payment) return;
    const form = document.getElementById('editPaymentForm');
    if (!form) return;

    const setVal = function (name, value) {
        const el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.value = value ?? '';
    };

    setVal('payment_id', payment.id);
    setVal('order_id', payment.order_id);
    setVal('amount', payment.amount);
    setVal('payment_type', payment.payment_type);
    setVal('payment_date', payment.payment_date);
    setVal('notes', payment.notes);
});
</script>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

