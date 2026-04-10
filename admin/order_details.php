<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensureOrderQuantityColumn();
ensureAdvancePaymentsBackfill();

$orderId = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    "SELECT o.*, c.customer_code, c.full_name AS customer_name, c.phone, c.address, u.full_name AS created_by_name
     FROM orders o
     INNER JOIN customers c ON c.id = o.customer_id
     LEFT JOIN users u ON u.id = o.created_by
     WHERE o.id = ?"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Order not found');
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

$paymentsStmt = db()->prepare(
    "SELECT p.*, u.full_name AS posted_by_name
     FROM payments p
     LEFT JOIN users u ON u.id = p.created_by
     WHERE p.order_id = ?
     ORDER BY p.id DESC"
);
$paymentsStmt->execute([$orderId]);
$payments = $paymentsStmt->fetchAll();

$historyStmt = db()->prepare(
    "SELECT h.*, u.full_name AS changed_by_name
     FROM order_status_history h
     LEFT JOIN users u ON u.id = h.changed_by
     WHERE h.order_id = ?
     ORDER BY h.id DESC"
);
$historyStmt->execute([$orderId]);
$history = $historyStmt->fetchAll();

function showVal(array $row, string $key): string
{
    $v = $row[$key] ?? '';
    if ($v === null || $v === '') {
        return '-';
    }
    return (string)$v;
}

function statusBadgeClass(string $status): string
{
    $s = strtolower(trim($status));
    return match ($s) {
        'ready', 'completed', 'delivered' => 'badge-soft-success',
        'in progress', 'processing', 'pending' => 'badge-soft-warning',
        'cancelled', 'rejected' => 'badge-soft-danger',
        default => 'badge-soft-info',
    };
}

$pageTitle = 'Order Details';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0">Order Details</h1>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/admin/order_details_pdf.php?id=<?= (int)$orderId ?>" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-pdf me-1"></i>Full PDF
        </a>
        <a class="btn btn-sm btn-outline-info" href="<?= BASE_URL ?>/admin/order_details_pdf.php?id=<?= (int)$orderId ?>&view=customer" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-person me-1"></i>Customer Copy
        </a>
        <a class="btn btn-sm btn-outline-success" href="<?= BASE_URL ?>/admin/order_details_pdf.php?id=<?= (int)$orderId ?>&view=tailor" target="_blank" rel="noopener">
            <i class="bi bi-rulers me-1"></i>Tailor PDF
        </a>
        <a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/admin/order_details_thermal.php?id=<?= (int)$orderId ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer me-1"></i>Thermal Print
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/orders.php">
            <i class="bi bi-arrow-left me-1"></i>Back to Orders
        </a>
    </div>
</div>

<div class="card shadow-sm mb-3 order-hero-card">
    <div class="card-body p-3 p-md-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <div class="small text-uppercase text-muted fw-semibold">Order Code</div>
                <div class="fs-5 fw-bold"><?= e($order['order_code']) ?></div>
                <div class="small text-muted">Tracking: <?= e($order['tracking_code']) ?></div>
            </div>
            <div class="text-md-end">
                <span class="badge badge-soft <?= e(statusBadgeClass((string)$order['current_status'])) ?> px-3 py-2">
                    <?= e($order['current_status']) ?>
                </span>
                <div class="small text-muted mt-2">Delivery: <?= e($order['delivery_date']) ?></div>
            </div>
        </div>
        <div class="row g-2 g-md-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Customer</div>
                    <div class="order-meta-value">
                        <a href="<?= BASE_URL ?>/admin/customer_profile.php?id=<?= (int)$order['customer_id'] ?>" class="text-decoration-none"><?= e($order['customer_name']) ?></a>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Phone</div>
                    <div class="order-meta-value"><?= e($order['phone']) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Total</div>
                    <div class="order-meta-value">Rs <?= number_format((float)$order['total_amount'], 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Quantity</div>
                    <div class="order-meta-value"><?= (int)($order['quantity'] ?? 1) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Paid</div>
                    <div class="order-meta-value">Rs <?= number_format((float)$order['paid_amount'], 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Balance</div>
                    <div class="order-meta-value">Rs <?= number_format((float)$order['balance_amount'], 0) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Created By</div>
                    <div class="order-meta-value"><?= e($order['created_by_name'] ?? '-') ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Created At</div>
                    <div class="order-meta-value"><?= e($order['created_at']) ?></div>
                </div>
            </div>
            <div class="col-12">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Address</div>
                    <div class="order-meta-value"><?= e($order['address'] ?? '-') ?></div>
                </div>
            </div>
            <div class="col-12">
                <div class="order-meta-chip">
                    <div class="order-meta-label">Special Instructions</div>
                    <div class="order-meta-value"><?= e($order['special_instructions'] ?? '-') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 order-sections">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 order-section-card">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <span class="section-icon"><i class="bi bi-rulers"></i></span>
                <h2 class="h6 mb-0">Kameez Measurements</h2>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0 order-details-table">
                    <tbody>
                    <tr><th>Length</th><td><?= e(showVal($kameez, 'length')) ?></td><th>Shoulder</th><td><?= e(showVal($kameez, 'shoulder')) ?></td></tr>
                    <tr><th>Chest</th><td><?= e(showVal($kameez, 'chest')) ?></td><th>Waist</th><td><?= e(showVal($kameez, 'waist')) ?></td></tr>
                    <tr><th>Hip</th><td><?= e(showVal($kameez, 'hip')) ?></td><th>Sleeve</th><td><?= e(showVal($kameez, 'sleeve_length')) ?></td></tr>
                    <tr><th>Arm Round</th><td><?= e(showVal($kameez, 'arm_round')) ?></td><th>Cuff</th><td><?= e(showVal($kameez, 'cuff')) ?></td></tr>
                    <tr><th>Neck</th><td><?= e(showVal($kameez, 'neck')) ?></td><th></th><td></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100 order-section-card">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <span class="section-icon"><i class="bi bi-columns-gap"></i></span>
                <h2 class="h6 mb-0">Shalwar Measurements</h2>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0 order-details-table">
                    <tbody>
                    <tr><th>Length</th><td><?= e(showVal($shalwar, 'length')) ?></td><th>Waist</th><td><?= e(showVal($shalwar, 'waist')) ?></td></tr>
                    <tr><th>Hip</th><td><?= e(showVal($shalwar, 'hip')) ?></td><th>Thigh</th><td><?= e(showVal($shalwar, 'thigh')) ?></td></tr>
                    <tr><th>Knee</th><td><?= e(showVal($shalwar, 'knee')) ?></td><th>Bottom</th><td><?= e(showVal($shalwar, 'bottom')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100 order-section-card">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <span class="section-icon"><i class="bi bi-palette2"></i></span>
                <h2 class="h6 mb-0">Style Options</h2>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0 order-details-table">
                    <tbody>
                    <tr><th>Collar Type</th><td><?= e(showVal($style, 'collar_type')) ?></td></tr>
                    <tr><th>Pocket</th><td><?= e(showVal($style, 'pocket')) ?></td></tr>
                    <tr><th>Cuff Style</th><td><?= e(showVal($style, 'cuff_style')) ?></td></tr>
                    <tr><th>Front Style</th><td><?= e(showVal($style, 'front_style')) ?></td></tr>
                    <tr><th>Design Instructions</th><td><?= e(showVal($style, 'special_instructions')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100 order-section-card">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <span class="section-icon"><i class="bi bi-clock-history"></i></span>
                <h2 class="h6 mb-0">Status History</h2>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 order-details-table">
                        <thead><tr><th>From</th><th>To</th><th>Changed At</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?= e($h['old_status'] ?? '-') ?></td>
                                <td><?= e($h['new_status']) ?></td>
                                <td><?= e($h['changed_at']) ?></td>
                                <td><?= e($h['changed_by_name'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?><tr><td colspan="4" class="text-center text-muted">No status history.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-3 order-section-card">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="section-icon"><i class="bi bi-cash-coin"></i></span>
        <h2 class="h6 mb-0">Payments for this Order</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 order-details-table">
                <thead><tr><th>Date</th><th>Type</th><th class="text-end">Amount</th><th>Notes</th><th>Posted By</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= e($p['payment_date']) ?></td>
                        <td><?= e(paymentTypeLabel((string)$p['payment_type'])) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$p['amount'], 0) ?></td>
                        <td><?= e($p['notes'] ?? '-') ?></td>
                        <td><?= e($p['posted_by_name'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?><tr><td colspan="5" class="text-center text-muted">No payments posted.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

