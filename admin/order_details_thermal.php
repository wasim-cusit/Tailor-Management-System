<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensureOrderQuantityColumn();
ensureAdvancePaymentsBackfill();

$orderId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT o.*, c.customer_code, c.full_name AS customer_name, c.phone
     FROM orders o
     INNER JOIN customers c ON c.id = o.customer_id
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

function v(array $r, string $k): string { return ($r[$k] ?? '') === '' ? '-' : (string)$r[$k]; }

$siteTitle = appSetting('site_title', APP_NAME);
$phone = appSetting('contact_phone', '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Thermal Print</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
        .receipt { width: 78mm; margin: 0 auto; padding: 6px; font-size: 11px; }
        .center { text-align: center; }
        .line { border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 2px 0; font-size: 10px; vertical-align: top; }
        .k { font-weight: bold; width: 38%; }
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
    <div class="center">Order Slip</div>
    <div class="line"></div>

    <table>
        <tr><td class="k">Order:</td><td><?= e($order['order_code']) ?></td></tr>
        <tr><td class="k">Tracking:</td><td><?= e($order['tracking_code']) ?></td></tr>
        <tr><td class="k">Customer:</td><td><?= e($order['customer_name']) ?></td></tr>
        <tr><td class="k">Customer Code:</td><td><?= e($order['customer_code']) ?></td></tr>
        <tr><td class="k">Phone:</td><td><?= e($order['phone']) ?></td></tr>
        <tr><td class="k">Delivery:</td><td><?= e($order['delivery_date']) ?></td></tr>
        <tr><td class="k">Status:</td><td><?= e($order['current_status']) ?></td></tr>
        <tr><td class="k">Qty:</td><td><?= (int)($order['quantity'] ?? 1) ?></td></tr>
        <tr><td class="k">Total:</td><td>Rs <?= number_format((float)$order['total_amount'], 0) ?></td></tr>
        <tr><td class="k">Paid:</td><td>Rs <?= number_format((float)$order['paid_amount'], 0) ?></td></tr>
        <tr><td class="k">Balance:</td><td>Rs <?= number_format((float)$order['balance_amount'], 0) ?></td></tr>
    </table>

    <div class="line"></div>
    <div><strong>Kameez</strong></div>
    <table>
        <tr><td class="k">Length</td><td><?= e(v($kameez, 'length')) ?></td><td class="k">Shoulder</td><td><?= e(v($kameez, 'shoulder')) ?></td></tr>
        <tr><td class="k">Chest</td><td><?= e(v($kameez, 'chest')) ?></td><td class="k">Waist</td><td><?= e(v($kameez, 'waist')) ?></td></tr>
        <tr><td class="k">Hip</td><td><?= e(v($kameez, 'hip')) ?></td><td class="k">Sleeve</td><td><?= e(v($kameez, 'sleeve_length')) ?></td></tr>
    </table>
    <div><strong>Shalwar</strong></div>
    <table>
        <tr><td class="k">Length</td><td><?= e(v($shalwar, 'length')) ?></td><td class="k">Waist</td><td><?= e(v($shalwar, 'waist')) ?></td></tr>
        <tr><td class="k">Hip</td><td><?= e(v($shalwar, 'hip')) ?></td><td class="k">Thigh</td><td><?= e(v($shalwar, 'thigh')) ?></td></tr>
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

