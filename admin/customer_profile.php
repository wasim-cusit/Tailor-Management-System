<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensureAdvancePaymentsBackfill();

$customerId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    http_response_code(404);
    exit('Customer not found');
}

$orders = db()->prepare(
    'SELECT o.*, u.full_name AS created_by_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.created_by
     WHERE o.customer_id = ?
     ORDER BY o.id DESC'
);
$orders->execute([$customerId]);
$orderRows = $orders->fetchAll();

$payments = db()->prepare(
    'SELECT *
     FROM (
         SELECT p.id, p.order_id, p.customer_id, p.amount, p.payment_type, p.payment_date, p.notes, p.created_at,
                u.full_name AS created_by_name, o.order_code
         FROM payments p
         LEFT JOIN users u ON u.id = p.created_by
         LEFT JOIN orders o ON o.id = p.order_id
         WHERE p.customer_id = ?

         UNION ALL

         SELECT (1000000000 + o.id) AS id, o.id AS order_id, o.customer_id, o.advance_amount AS amount, "advance" AS payment_type,
                DATE(o.created_at) AS payment_date, "Advance received at order time" AS notes, o.created_at AS created_at,
                u.full_name AS created_by_name, o.order_code
         FROM orders o
         LEFT JOIN users u ON u.id = o.created_by
         WHERE o.customer_id = ?
           AND o.advance_amount > 0
           AND NOT EXISTS (
               SELECT 1 FROM payments p2
               WHERE p2.order_id = o.id
                 AND p2.payment_type = "advance"
           )
     ) x
     ORDER BY x.id DESC'
);
$payments->execute([$customerId, $customerId]);
$paymentRows = $payments->fetchAll();

$ledger = db()->prepare(
    'SELECT *
     FROM (
         SELECT
             CONCAT("O", o.id) AS rid,
             o.id AS order_id,
             o.customer_id,
             "debit" AS entry_type,
             o.total_amount AS amount,
             o.order_code AS reference,
             "Order billed" AS notes,
             DATE(o.created_at) AS entry_date,
             o.created_at,
             o.order_code
         FROM orders o
         WHERE o.customer_id = ?

         UNION ALL

         SELECT
             CONCAT("P", p.id) AS rid,
             p.order_id,
             p.customer_id,
             "credit" AS entry_type,
             p.amount,
             o.order_code AS reference,
             CASE
                 WHEN COALESCE(p.notes, "") <> "" THEN p.notes
                 ELSE "Payment received"
             END AS notes,
             p.payment_date AS entry_date,
             p.created_at,
             o.order_code
         FROM payments p
         LEFT JOIN orders o ON o.id = p.order_id
         WHERE p.customer_id = ?
     ) x
     ORDER BY x.entry_date ASC, x.created_at ASC'
);
$ledger->execute([$customerId, $customerId]);
$ledgerRows = $ledger->fetchAll();

$historyStmt = db()->prepare(
    'SELECT h.*, u.full_name AS changed_by_name, o.order_code
     FROM order_status_history h
     INNER JOIN orders o ON o.id = h.order_id
     LEFT JOIN users u ON u.id = h.changed_by
     WHERE o.customer_id = ?
     ORDER BY h.id DESC
     LIMIT 200'
);
$historyStmt->execute([$customerId]);
$statusHistory = $historyStmt->fetchAll();

$running = 0.0;
$totalOrders = count($orderRows);
$outstanding = 0.0;
foreach ($orderRows as $o) {
    $outstanding += (float)$o['balance_amount'];
}

$waCustomerNumber = preg_replace('/\D+/', '', (string)($customer['phone'] ?? ''));
$waMessage = "Customer Details\n"
    . "Name: " . ($customer['full_name'] ?? '') . "\n"
    . "Code: " . ($customer['customer_code'] ?? '') . "\n"
    . "Phone: " . ($customer['phone'] ?? '') . "\n"
    . "Address: " . ($customer['address'] ?? '') . "\n"
    . "Total Orders: " . $totalOrders . "\n"
    . "Outstanding: Rs " . number_format($outstanding, 0) . "\n";
$waCustomerLink = $waCustomerNumber !== ''
    ? ('https://wa.me/' . $waCustomerNumber . '?text=' . rawurlencode($waMessage))
    : '';

$pageTitle = 'Customer Profile';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';

function statusBadgeClass(string $status): string
{
    $s = strtolower(trim($status));
    return match (true) {
        $s === '' => 'badge-soft-secondary',
        str_contains($s, 'delivered') => 'badge-soft-success',
        str_contains($s, 'complete') => 'badge-soft-success',
        str_contains($s, 'ready') => 'badge-soft-success',
        str_contains($s, 'cancel') => 'badge-soft-danger',
        str_contains($s, 'reject') => 'badge-soft-danger',
        str_contains($s, 'hold') => 'badge-soft-warning',
        str_contains($s, 'pending') => 'badge-soft-warning',
        str_contains($s, 'progress') => 'badge-soft-info',
        str_contains($s, 'stitch') => 'badge-soft-info',
        default => 'badge-soft-primary',
    };
}
?>
<div class="card shadow-sm mb-3 customer-hero-card">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-center">
            <div class="d-flex flex-column gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="customer-avatar">
                        <i class="bi bi-person"></i>
                    </div>
                    <div>
                        <h1 class="h4 mb-0 text-white"><?= e($customer['full_name']) ?></h1>
                        <div class="customer-code-line">
                            <span class="badge badge-soft badge-soft-secondary me-1"><?= e($customer['customer_code']) ?></span>
                            <span class="text-white-75 small">Created <?= e($customer['created_at']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="customer-contact text-white-75 small">
                    <div>
                        <i class="bi bi-telephone me-1"></i>
                        <?php if (!empty($customer['phone'])): ?>
                            <a href="tel:<?= e(preg_replace('/\s+/', '', $customer['phone'])) ?>" class="link-light text-decoration-underline">
                                <?= e($customer['phone']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div><i class="bi bi-geo-alt me-1"></i><?= e($customer['address']) ?></div>
                </div>
                <?php if (!empty($customer['notes'])): ?>
                    <div class="customer-notes text-white-75 small">
                        <i class="bi bi-chat-left-text me-1"></i><strong>Notes:</strong> <?= e($customer['notes']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-row flex-md-column gap-2 flex-shrink-0 customer-metrics">
                <div class="customer-actions">
                    <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>/admin/customers.php">
                        <i class="bi bi-arrow-left me-1"></i>Back to Customer List
                    </a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item <?= $waCustomerLink === '' ? 'disabled' : '' ?>" <?= $waCustomerLink === '' ? 'aria-disabled="true"' : '' ?> href="<?= e($waCustomerLink) ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-send me-2"></i>Send to customer
                                </a>
                            </li>
                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#waOtherModal">
                                    <i class="bi bi-telephone-plus me-2"></i>Send to another number
                                </button></li>
                        </ul>
                    </div>
                    <a class="btn btn-sm btn-light" href="<?= BASE_URL ?>/admin/customer_profile_pdf.php?id=<?= (int)$customerId ?>" target="_blank" rel="noopener">
                        <i class="bi bi-file-earmark-pdf me-1 text-danger"></i>PDF
                    </a>
                </div>
                <div class="metric-pill metric-pill--orders">
                    <div class="metric-label">Total Orders</div>
                    <div class="metric-value"><?= (int)$totalOrders ?></div>
                </div>
                <div class="metric-pill metric-pill--outstanding">
                    <div class="metric-label">Outstanding</div>
                    <div class="metric-value">Rs <?= number_format($outstanding, 0) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="waOtherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-whatsapp me-2 text-success"></i>Send via WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-muted small">Enter a WhatsApp number in international format (example: 923001234567).</div>
                <label class="form-label">WhatsApp Number</label>
                <input id="waOtherNumber" type="text" class="form-control" placeholder="923001234567">
                <div class="form-text">We will open WhatsApp Web/app with the prepared message.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="waOtherSendBtn">
                    <i class="bi bi-send me-1"></i>Send
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3 profile-section profile-section--orders">
    <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <h2 class="h6 mb-0 d-flex align-items-center gap-2">
            <span class="section-icon"><i class="bi bi-bag-check"></i></span>
            <span>Orders</span>
        </h2>
        <div class="profile-table-search">
            <span class="profile-table-search-icon"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control form-control-sm profile-table-filter" data-filter-table="ordersTable" placeholder="Search orders..." aria-label="Search orders">
        </div>
    </div>
    <div class="card-body p-3">
        <div class="table-responsive">
            <table id="ordersTable" class="table table-sm align-middle mb-0 profile-table">
                <thead>
                <tr>
                    <th>Order</th>
                    <th>Tracking</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th>Created</th>
                    <th>Created By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orderRows as $o): ?>
                    <tr>
                        <td><?= e($o['order_code']) ?></td>
                        <td><?= e($o['tracking_code']) ?></td>
                        <td><span class="badge badge-soft <?= statusBadgeClass((string)($o['current_status'] ?? '')) ?>"><?= e($o['current_status']) ?></span></td>
                        <td><?= e($o['delivery_date']) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$o['total_amount'], 0) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$o['paid_amount'], 0) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$o['balance_amount'], 0) ?></td>
                        <td class="text-muted small"><?= e($o['created_at']) ?></td>
                        <td class="text-muted small"><?= e($o['created_by_name'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orderRows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No orders found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3 profile-section profile-section--payments">
    <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <h2 class="h6 mb-0 d-flex align-items-center gap-2">
            <span class="section-icon"><i class="bi bi-cash-coin"></i></span>
            <span>Payments</span>
        </h2>
        <div class="profile-table-search">
            <span class="profile-table-search-icon"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control form-control-sm profile-table-filter" data-filter-table="paymentsTable" placeholder="Search payments..." aria-label="Search payments">
        </div>
    </div>
    <div class="card-body p-3">
        <div class="table-responsive">
            <table id="paymentsTable" class="table table-sm align-middle mb-0 profile-table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Order</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th>Notes</th>
                    <th>Posted</th>
                    <th>Posted By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($paymentRows as $p): ?>
                    <tr>
                        <td><?= e($p['payment_date']) ?></td>
                        <td><?= e($p['order_code'] ?? '') ?></td>
                        <td><span class="badge badge-soft badge-soft-success"><?= e($p['payment_type']) ?></span></td>
                        <td class="text-end">Rs <?= number_format((float)$p['amount'], 0) ?></td>
                        <td class="text-muted"><?= e($p['notes']) ?></td>
                        <td class="text-muted small"><?= e($p['created_at']) ?></td>
                        <td class="text-muted small"><?= e($p['created_by_name'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$paymentRows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No payments found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3 profile-section profile-section--ledger">
    <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <h2 class="h6 mb-0 d-flex align-items-center gap-2">
            <span class="section-icon"><i class="bi bi-journal-text"></i></span>
            <span>Ledger</span>
        </h2>
        <div class="profile-table-search">
            <span class="profile-table-search-icon"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control form-control-sm profile-table-filter" data-filter-table="ledgerTable" placeholder="Search ledger..." aria-label="Search ledger">
        </div>
    </div>
    <div class="card-body p-3">
        <div class="table-responsive">
            <table id="ledgerTable" class="table table-sm align-middle mb-0 profile-table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Order</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th>Reference</th>
                    <th>Notes</th>
                    <th>Created</th>
                </tr>
                </thead>
                <tbody>
                <?php $running = 0.0; ?>
                <?php foreach ($ledgerRows as $l): ?>
                    <?php $running += $l['entry_type'] === 'debit' ? (float)$l['amount'] : -(float)$l['amount']; ?>
                    <tr>
                        <td><?= e($l['entry_date']) ?></td>
                        <td><?= e($l['order_code'] ?? '') ?></td>
                        <td>
                            <?php $isDebit = (($l['entry_type'] ?? '') === 'debit'); ?>
                            <span class="badge badge-soft <?= $isDebit ? 'badge-soft-danger' : 'badge-soft-success' ?>">
                                <?= e($l['entry_type']) ?>
                            </span>
                        </td>
                        <td class="text-end">Rs <?= number_format((float)$l['amount'], 0) ?></td>
                        <td class="text-muted"><?= e($l['reference']) ?></td>
                        <td class="text-muted"><?= e($l['notes']) ?></td>
                        <td class="text-muted small"><?= e($l['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ledgerRows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No ledger entries found.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th colspan="3" class="text-end">Running Balance</th>
                    <th class="text-end">Rs <?= number_format($running, 0) ?></th>
                    <th colspan="3"></th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm profile-section profile-section--history">
    <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <h2 class="h6 mb-0 d-flex align-items-center gap-2">
            <span class="section-icon"><i class="bi bi-clock-history"></i></span>
            <span>Status History</span>
        </h2>
        <div class="profile-table-search">
            <span class="profile-table-search-icon"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control form-control-sm profile-table-filter" data-filter-table="historyTable" placeholder="Search history..." aria-label="Search status history">
        </div>
    </div>
    <div class="card-body p-3">
        <div class="table-responsive">
            <table id="historyTable" class="table table-sm align-middle mb-0 profile-table">
                <thead>
                <tr>
                    <th>Order</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Changed</th>
                    <th>Changed By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($statusHistory as $h): ?>
                    <tr>
                        <td><?= e($h['order_code'] ?? '') ?></td>
                        <td><?= e($h['old_status'] ?? '') ?></td>
                        <td><span class="badge badge-soft <?= statusBadgeClass((string)($h['new_status'] ?? '')) ?>"><?= e($h['new_status']) ?></span></td>
                        <td class="text-muted small"><?= e($h['changed_at']) ?></td>
                        <td class="text-muted small"><?= e($h['changed_by_name'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$statusHistory): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No status history found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm d-none">
    <div class="card-header bg-white">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabOrders" type="button" role="tab">Orders</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPayments" type="button" role="tab">Payments</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabLedger" type="button" role="tab">Ledger</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabHistory" type="button" role="tab">Status History</button></li>
        </ul>
    </div>
    <div class="card-body p-3">
        <div class="tab-content">
    <div class="tab-pane fade show active" id="tabOrders" role="tabpanel">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>Order</th>
                    <th>Tracking</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th>Created</th>
                    <th>Created By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orderRows as $o): ?>
                    <tr>
                        <td><?= e($o['order_code']) ?></td>
                        <td><?= e($o['tracking_code']) ?></td>
                        <td><span class="badge text-bg-light"><?= e($o['current_status']) ?></span></td>
                        <td><?= e($o['delivery_date']) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$o['total_amount'], 0) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$o['paid_amount'], 0) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$o['balance_amount'], 0) ?></td>
                        <td class="text-muted small"><?= e($o['created_at']) ?></td>
                        <td class="text-muted small"><?= e($o['created_by_name'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orderRows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No orders found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-pane fade" id="tabPayments" role="tabpanel">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Order</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th>Notes</th>
                    <th>Posted</th>
                    <th>Posted By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($paymentRows as $p): ?>
                    <tr>
                        <td><?= e($p['payment_date']) ?></td>
                        <td><?= e($p['order_code'] ?? '') ?></td>
                        <td><span class="badge text-bg-success-subtle text-success"><?= e($p['payment_type']) ?></span></td>
                        <td class="text-end">Rs <?= number_format((float)$p['amount'], 0) ?></td>
                        <td class="text-muted"><?= e($p['notes']) ?></td>
                        <td class="text-muted small"><?= e($p['created_at']) ?></td>
                        <td class="text-muted small"><?= e($p['created_by_name'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$paymentRows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No payments found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-pane fade" id="tabLedger" role="tabpanel">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Order</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th>Reference</th>
                    <th>Notes</th>
                    <th>Created</th>
                </tr>
                </thead>
                <tbody>
                <?php $running = 0.0; ?>
                <?php foreach ($ledgerRows as $l): ?>
                    <?php $running += $l['entry_type'] === 'debit' ? (float)$l['amount'] : -(float)$l['amount']; ?>
                    <tr>
                        <td><?= e($l['entry_date']) ?></td>
                        <td><?= e($l['order_code'] ?? '') ?></td>
                        <td><?= e($l['entry_type']) ?></td>
                        <td class="text-end">Rs <?= number_format((float)$l['amount'], 0) ?></td>
                        <td class="text-muted"><?= e($l['reference']) ?></td>
                        <td class="text-muted"><?= e($l['notes']) ?></td>
                        <td class="text-muted small"><?= e($l['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ledgerRows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No ledger entries found.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th colspan="3" class="text-end">Running Balance</th>
                    <th class="text-end">Rs <?= number_format($running, 0) ?></th>
                    <th colspan="3"></th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="tab-pane fade" id="tabHistory" role="tabpanel">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>Order</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Changed</th>
                    <th>Changed By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($statusHistory as $h): ?>
                    <tr>
                        <td><?= e($h['order_code'] ?? '') ?></td>
                        <td><?= e($h['old_status'] ?? '') ?></td>
                        <td><span class="badge text-bg-light"><?= e($h['new_status']) ?></span></td>
                        <td class="text-muted small"><?= e($h['changed_at']) ?></td>
                        <td class="text-muted small"><?= e($h['changed_by_name'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$statusHistory): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No status history found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function ensureNoMatchRow(tbody, colCount) {
        var existing = tbody.querySelector('tr[data-no-match="1"]');
        if (existing) {
            return existing;
        }
        var tr = document.createElement('tr');
        tr.setAttribute('data-no-match', '1');
        tr.style.display = 'none';
        var td = document.createElement('td');
        td.colSpan = colCount || 1;
        td.className = 'text-center text-muted py-4';
        td.textContent = 'No matching records found.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return tr;
    }

    var inputs = document.querySelectorAll('.profile-table-filter');
    inputs.forEach(function (input) {
        input.addEventListener('input', function () {
            var tableId = input.getAttribute('data-filter-table');
            var table = document.getElementById(tableId);
            if (!table) {
                return;
            }
            var tbody = table.querySelector('tbody');
            if (!tbody) {
                return;
            }
            var headCols = table.querySelectorAll('thead th').length;
            var noMatchRow = ensureNoMatchRow(tbody, headCols);
            var rows = tbody.querySelectorAll('tr');
            var term = input.value.trim().toLowerCase();
            var visibleCount = 0;
            rows.forEach(function (row) {
                if (row.getAttribute('data-no-match') === '1') {
                    return;
                }
                if (row.querySelector('td[colspan]')) {
                    return;
                }
                var text = row.textContent.toLowerCase();
                var show = !term || text.indexOf(term) !== -1;
                row.style.display = show ? '' : 'none';
                if (show) {
                    visibleCount += 1;
                }
            });

            // Only show "no match" when there are rows but none matched the term.
            // If the table is truly empty, the server-rendered "No X found" row stays visible.
            if (term && visibleCount === 0) {
                noMatchRow.style.display = '';
            } else {
                noMatchRow.style.display = 'none';
            }
        });
    });

    var waOtherSendBtn = document.getElementById('waOtherSendBtn');
    if (waOtherSendBtn) {
        waOtherSendBtn.addEventListener('click', function () {
            var raw = (document.getElementById('waOtherNumber') || {}).value || '';
            var digits = raw.replace(/\D+/g, '');
            if (!digits) return;
            var msg = <?= json_encode($waMessage, JSON_UNESCAPED_UNICODE) ?>;
            var url = 'https://wa.me/' + digits + '?text=' + encodeURIComponent(msg);
            window.open(url, '_blank', 'noopener');
        });
    }
});
</script>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

