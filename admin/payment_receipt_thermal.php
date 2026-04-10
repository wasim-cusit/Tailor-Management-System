<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();

$paymentId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT p.*, o.order_code, o.tracking_code, o.total_amount, o.paid_amount, o.balance_amount,
            c.customer_code, c.full_name AS customer_name, c.phone, u.full_name AS posted_by_name
     FROM payments p
     INNER JOIN orders o ON o.id = p.order_id
     INNER JOIN customers c ON c.id = p.customer_id
     LEFT JOIN users u ON u.id = p.created_by
     WHERE p.id = ?"
);
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Payment not found');
}

$siteTitle = appSetting('site_title', APP_NAME);
$phone = appSetting('contact_phone', '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Thermal Print</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
        .receipt { width: 78mm; margin: 0 auto; padding: 6px; font-size: 11px; }
        .center { text-align: center; }
        .line { border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 2px 0; font-size: 10px; vertical-align: top; }
        .k { font-weight: bold; width: 42%; }
        @media print {
            @page { size: 80mm auto; margin: 2mm; }
            .noprint { display: none; }
        }
    </style>
</head>
<body>
<div class="receipt">
    <div class="center"><strong><?= e($siteTitle) ?></strong></div>
    <?php if ($phone !== ''): ?><div class="center">Phone: <?= e($phone) ?></div><?php endif; ?>
    <div class="center">Payment Receipt</div>
    <div class="line"></div>

    <table>
        <tr><td class="k">Receipt #:</td><td><?= (int)$payment['id'] ?></td></tr>
        <tr><td class="k">Date:</td><td><?= e($payment['payment_date']) ?></td></tr>
        <tr><td class="k">Order:</td><td><?= e($payment['order_code']) ?></td></tr>
        <tr><td class="k">Tracking:</td><td><?= e($payment['tracking_code']) ?></td></tr>
        <tr><td class="k">Customer:</td><td><?= e($payment['customer_name']) ?></td></tr>
        <tr><td class="k">Customer Code:</td><td><?= e($payment['customer_code']) ?></td></tr>
        <tr><td class="k">Phone:</td><td><?= e($payment['phone']) ?></td></tr>
        <tr><td class="k">Type:</td><td><?= e(paymentTypeLabel((string)$payment['payment_type'])) ?></td></tr>
        <tr><td class="k">Amount:</td><td><strong>Rs <?= number_format((float)$payment['amount'], 0) ?></strong></td></tr>
        <tr><td class="k">Order Total:</td><td>Rs <?= number_format((float)$payment['total_amount'], 0) ?></td></tr>
        <tr><td class="k">Total Paid:</td><td>Rs <?= number_format((float)$payment['paid_amount'], 0) ?></td></tr>
        <tr><td class="k">Remaining:</td><td>Rs <?= number_format((float)$payment['balance_amount'], 0) ?></td></tr>
        <tr><td class="k">Posted By:</td><td><?= e($payment['posted_by_name'] ?? '-') ?></td></tr>
        <tr><td class="k">Notes:</td><td><?= e($payment['notes'] ?: '-') ?></td></tr>
    </table>

    <div class="line"></div>
    <div class="center">Generated: <?= e(date('Y-m-d H:i')) ?></div>
    <div class="center noprint" style="margin-top:8px;">
        <button onclick="window.print()">Print</button>
    </div>
</div>
<script>
setTimeout(function () { window.print(); }, 300);
</script>
</body>
</html>

