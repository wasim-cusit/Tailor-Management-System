<?php
require_once __DIR__ . '/config/functions.php';
requireCustomerLogin();

$customerId = (int)$_SESSION['customer_id'];
$customerName = (string)($_SESSION['customer_name'] ?? 'Customer');

$ordersStmt = db()->prepare(
    "SELECT order_code, tracking_code, delivery_date, current_status, total_amount, paid_amount, balance_amount
     FROM orders WHERE customer_id = ? ORDER BY id DESC"
);
$ordersStmt->execute([$customerId]);
$orders = $ordersStmt->fetchAll();

$pageTitle = 'My Orders';
require __DIR__ . '/includes/header.php';
?>
<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="h4">Welcome, <?= e($customerName) ?></h1>
        <p class="text-muted mb-3">Customer Code: <?= e((string)($_SESSION['customer_code'] ?? '')) ?></p>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Tracking</th>
                        <th>Status</th>
                        <th>Delivery</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><?= e($o['order_code']) ?></td>
                            <td><?= e($o['tracking_code']) ?></td>
                            <td><?= e($o['current_status']) ?></td>
                            <td><?= e($o['delivery_date']) ?></td>
                            <td>Rs <?= number_format((float)$o['total_amount'], 0) ?></td>
                            <td>Rs <?= number_format((float)$o['paid_amount'], 0) ?></td>
                            <td>Rs <?= number_format((float)$o['balance_amount'], 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

