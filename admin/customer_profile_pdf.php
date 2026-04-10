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
    'SELECT o.*
     FROM orders o
     WHERE o.customer_id = ?
     ORDER BY o.id DESC
     LIMIT 200'
);
$orders->execute([$customerId]);
$orderRows = $orders->fetchAll();

$payments = db()->prepare(
    'SELECT *
     FROM (
         SELECT p.id, p.order_id, p.customer_id, p.amount, p.payment_type, p.payment_date, p.notes, p.created_at, o.order_code
         FROM payments p
         LEFT JOIN orders o ON o.id = p.order_id
         WHERE p.customer_id = ?

         UNION ALL

         SELECT (1000000000 + o.id) AS id, o.id AS order_id, o.customer_id, o.advance_amount AS amount, "advance" AS payment_type,
                DATE(o.created_at) AS payment_date, "Advance received at order time" AS notes, o.created_at AS created_at, o.order_code
         FROM orders o
         WHERE o.customer_id = ?
           AND o.advance_amount > 0
           AND NOT EXISTS (
               SELECT 1 FROM payments p2
               WHERE p2.order_id = o.id
                 AND p2.payment_type = "advance"
           )
     ) x
     ORDER BY x.id DESC
     LIMIT 200'
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

$totalOrders = count($orderRows);
$outstanding = 0.0;
$totalAmount = 0.0;
$totalPaid = 0.0;
foreach ($orderRows as $o) {
    $totalAmount += (float)$o['total_amount'];
    $totalPaid += (float)$o['paid_amount'];
    $outstanding += (float)$o['balance_amount'];
}

$siteTitle = appSetting('site_title', APP_NAME);
$siteLogo = appSetting('site_logo', '');
$companyLine1 = appSetting('contact_address', appSetting('company_line1', ''));
$companyLine2 = appSetting('contact_phone', appSetting('company_line2', ''));

$pageTitle = 'Customer PDF';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #0b1220;
            color: #e5e7eb;
            margin: 0;
        }

        .download-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .download-card {
            width: 100%;
            max-width: 640px;
            background: radial-gradient(circle at top left, rgba(74, 91, 185, 0.35) 0, rgba(15, 23, 42, 0.95) 45%, rgba(2, 6, 23, 1) 100%);
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-radius: 16px;
            padding: 18px 18px;
            box-shadow: 0 20px 55px rgba(0,0,0,0.35);
        }

        .download-title {
            font-weight: 800;
            letter-spacing: 0.02em;
            margin: 0;
            font-size: 1.05rem;
        }

        .download-sub {
            margin: 0.35rem 0 0;
            color: rgba(229, 231, 235, 0.8);
            font-size: 0.9rem;
            line-height: 1.4;
        }
    </style>
</head>
<body>
<div class="download-shell">
    <div class="download-card">
        <p class="download-title">Preparing your PDF download…</p>
        <p class="download-sub">
            <?= e($siteTitle) ?><br>
            Customer: <strong><?= e($customer['full_name']) ?></strong> (<?= e($customer['customer_code']) ?>)
        </p>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-light" id="btnDownload">Download PDF</button>
            <a class="btn btn-sm btn-outline-light" href="<?= BASE_URL ?>/admin/customer_profile.php?id=<?= (int)$customerId ?>">Back</a>
        </div>
        <div class="mt-3 small" style="color: rgba(229,231,235,0.75);">
            If the download doesn’t start automatically, click “Download PDF”.
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.3/dist/jspdf.plugin.autotable.min.js"></script>
<script>
const payload = <?= json_encode([
    'company' => [
        'title' => $siteTitle,
        'logo' => $siteLogo,
        'line1' => $companyLine1,
        'line2' => $companyLine2,
    ],
    'generatedAt' => date('Y-m-d H:i'),
    'customer' => [
        'full_name' => $customer['full_name'] ?? '',
        'customer_code' => $customer['customer_code'] ?? '',
        'created_at' => $customer['created_at'] ?? '',
        'phone' => $customer['phone'] ?? '',
        'address' => $customer['address'] ?? '',
        'notes' => $customer['notes'] ?? '',
        'total_orders' => (int)$totalOrders,
        'total_amount' => number_format($totalAmount, 0),
        'total_paid' => number_format($totalPaid, 0),
        'outstanding' => number_format($outstanding, 0),
    ],
    'orders' => array_map(static function ($o) {
        return [
            $o['order_code'] ?? '',
            $o['current_status'] ?? '',
            $o['delivery_date'] ?? '',
            'Rs ' . number_format((float)($o['total_amount'] ?? 0), 0),
            'Rs ' . number_format((float)($o['paid_amount'] ?? 0), 0),
            'Rs ' . number_format((float)($o['balance_amount'] ?? 0), 0),
        ];
    }, $orderRows),
    'payments' => array_map(static function ($p) {
        return [
            $p['payment_date'] ?? '',
            $p['order_code'] ?? '',
            $p['payment_type'] ?? '',
            'Rs ' . number_format((float)($p['amount'] ?? 0), 0),
            (string)($p['notes'] ?? ''),
        ];
    }, $paymentRows),
    'ledger' => array_map(static function ($l) {
        return [
            $l['entry_date'] ?? '',
            $l['order_code'] ?? '',
            $l['entry_type'] ?? '',
            'Rs ' . number_format((float)($l['amount'] ?? 0), 0),
            (string)($l['reference'] ?? ''),
            (string)($l['notes'] ?? ''),
        ];
    }, $ledgerRows),
    'history' => array_map(static function ($h) {
        return [
            $h['order_code'] ?? '',
            $h['old_status'] ?? '',
            $h['new_status'] ?? '',
            $h['changed_at'] ?? '',
            (string)($h['changed_by_name'] ?? ''),
        ];
    }, $statusHistory),
], JSON_UNESCAPED_UNICODE) ?>;

async function loadImageAsDataUrl(url) {
    if (!url) return null;
    try {
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) return null;
        const blob = await res.blob();
        return await new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => resolve(null);
            reader.readAsDataURL(blob);
        });
    } catch {
        return null;
    }
}

async function generateAndDownload() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'pt', format: 'a4' });

    const brand1 = [31, 42, 68];
    const brand2 = [13, 110, 253]; // primary blue
    const ink = [15, 23, 42];
    const muted = [71, 85, 105];
    const border = [60, 60, 60]; // darker borders like sample

    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();
    const marginX = 40;
    const headerH = 86;
    const footerH = 26;

    function drawHeader(logoData) {
        // White header like sample
        doc.setFillColor(255, 255, 255);
        doc.rect(0, 0, pageW, headerH, 'F');

        // Logo top-center
        if (logoData) {
            try { doc.addImage(logoData, 'PNG', pageW / 2 - 14, 10, 28, 28); } catch {}
        }

        // Company title centered
        doc.setTextColor(...ink);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(13.5);
        doc.text(payload.company.title || 'Company', pageW / 2, 52, { align: 'center' });

        // Company details centered in header
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.setTextColor(...muted);
        if (payload.company.line1) {
            doc.text(`Address: ${payload.company.line1}`, pageW / 2, 64, { align: 'center' });
        }
        if (payload.company.line2) {
            doc.text(`Phone: ${payload.company.line2}`, pageW / 2, 74, { align: 'center' });
        }

        // Report title (centered, no bar)
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10.5);
        doc.setTextColor(...ink);
        doc.text('Customer Report', pageW / 2, 88, { align: 'center' });

        // Meta line (like sample) + blue separator
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.4);
        doc.setTextColor(...muted);
        const meta = `Code: ${payload.customer.customer_code || ''}  |  Total: ${payload.customer.total_amount || '0'} PKR  |  Paid: ${payload.customer.total_paid || '0'} PKR  |  Remaining: ${payload.customer.outstanding || '0'} PKR  |  Generated: ${payload.generatedAt}`;
        doc.text(meta, pageW / 2, 100, { align: 'center', maxWidth: pageW - marginX * 2 });

        doc.setDrawColor(...brand2);
        doc.setLineWidth(1);
        doc.line(marginX, 108, pageW - marginX, 108);

        doc.setTextColor(...ink);
    }

    function drawFooter() {
        const pageCount = doc.getNumberOfPages();
        const pageNumber = doc.internal.getCurrentPageInfo().pageNumber;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.setTextColor(...muted);
        doc.text(`Page ${pageNumber} of ${pageCount}`, pageW / 2, pageH - 10, { align: 'center' });
    }

    // section header bar is now part of table head (sample style)

    let logoUrl = payload.company.logo || '';
    if (logoUrl && logoUrl.startsWith('/')) {
        logoUrl = window.location.origin + logoUrl;
    }
    const logoData = await loadImageAsDataUrl(logoUrl);
    // Draw header on first page
    drawHeader(logoData);

    const summaryBody = [
        ['Customer', payload.customer.full_name || ''],
        ['Code', payload.customer.customer_code || ''],
        ['Created', payload.customer.created_at || ''],
        ['Phone', payload.customer.phone || ''],
        ['Address', payload.customer.address || ''],
        ['Notes', payload.customer.notes || '—'],
        ['Total Orders', String(payload.customer.total_orders ?? '')],
        ['Outstanding', `Rs ${payload.customer.outstanding || '0'}`],
    ];

    doc.autoTable({
        startY: 122,
        head: [[
            { content: 'Customer Summary', colSpan: 2, styles: { fillColor: brand2, textColor: [255,255,255], halign: 'left', fontStyle: 'bold' } }
        ]],
        body: summaryBody,
        theme: 'grid',
        styles: { font: 'helvetica', fontSize: 8.6, textColor: ink, cellPadding: { top: 4.5, right: 6, bottom: 4.5, left: 6 }, overflow: 'linebreak', lineColor: border, lineWidth: 0.8 },
        columnStyles: {
            0: { cellWidth: 145, fillColor: [255,255,255], textColor: ink, fontStyle: 'bold' },
            1: { cellWidth: 370 },
        },
        margin: { left: marginX, right: marginX, top: 112, bottom: footerH + 10 },
        didDrawPage: function () {
            drawHeader(logoData);
            drawFooter();
        },
    });

    let y = doc.lastAutoTable.finalY + 14;
    doc.autoTable({
        startY: y,
        head: [
            [{ content: 'Orders', colSpan: 6, styles: { fillColor: [255,255,255], textColor: ink, halign: 'left', fontStyle: 'bold' } }],
            ['Order', 'Status', 'Delivery', 'Total', 'Paid', 'Balance'],
        ],
        body: payload.orders.length
            ? payload.orders
            : [[{ content: 'No orders found.', colSpan: 6, styles: { halign: 'center', textColor: muted, fontStyle: 'italic' } }]],
        theme: 'grid',
        styles: { font: 'helvetica', fontSize: 8.1, cellPadding: { top: 4.2, right: 5.2, bottom: 4.2, left: 5.2 }, overflow: 'linebreak', lineColor: border, lineWidth: 0.8 },
        headStyles: { fillColor: brand2, textColor: [255,255,255], fontStyle: 'bold', minCellHeight: 16, cellPadding: { top: 5, right: 6, bottom: 5, left: 6 } },
        columnStyles: {
            0: { cellWidth: 150 },
            1: { cellWidth: 85 },
            2: { cellWidth: 88 },
            3: { cellWidth: 64, halign: 'right' },
            4: { cellWidth: 64, halign: 'right' },
            5: { cellWidth: 64, halign: 'right' },
        },
        margin: { left: marginX, right: marginX, top: headerH + 10, bottom: footerH + 10 },
    });

    y = doc.lastAutoTable.finalY + 16;
    doc.autoTable({
        startY: y,
        head: [
            [{ content: 'Payments', colSpan: 5, styles: { fillColor: [255,255,255], textColor: ink, halign: 'left', fontStyle: 'bold' } }],
            ['Date', 'Order', 'Type', 'Amount', 'Notes'],
        ],
        body: payload.payments.length
            ? payload.payments
            : [[{ content: 'No payments found.', colSpan: 5, styles: { halign: 'center', textColor: muted, fontStyle: 'italic' } }]],
        theme: 'grid',
        styles: { font: 'helvetica', fontSize: 8.2, cellPadding: { top: 4.5, right: 5.5, bottom: 4.5, left: 5.5 }, overflow: 'linebreak', lineColor: border, lineWidth: 0.8 },
        headStyles: { fillColor: brand2, textColor: [255,255,255], fontStyle: 'bold', minCellHeight: 16 },
        columnStyles: {
            0: { cellWidth: 80 },
            1: { cellWidth: 102 },
            2: { cellWidth: 95 },
            3: { cellWidth: 70, halign: 'right' },
            4: { cellWidth: 168 },
        },
        margin: { left: marginX, right: marginX, top: headerH + 10, bottom: footerH + 10 },
    });

    y = doc.lastAutoTable.finalY + 16;
    doc.autoTable({
        startY: y,
        head: [
            [{ content: 'Ledger', colSpan: 6, styles: { fillColor: [255,255,255], textColor: ink, halign: 'left', fontStyle: 'bold' } }],
            ['Date', 'Order', 'Type', 'Amount', 'Reference', 'Notes'],
        ],
        body: payload.ledger.length
            ? payload.ledger
            : [[{ content: 'No ledger entries found.', colSpan: 6, styles: { halign: 'center', textColor: muted, fontStyle: 'italic' } }]],
        theme: 'grid',
        styles: { font: 'helvetica', fontSize: 8.0, cellPadding: { top: 4.5, right: 5.5, bottom: 4.5, left: 5.5 }, overflow: 'linebreak', lineColor: border, lineWidth: 0.8 },
        headStyles: { fillColor: brand2, textColor: [255,255,255], fontStyle: 'bold', minCellHeight: 16 },
        columnStyles: {
            0: { cellWidth: 64 },
            1: { cellWidth: 102 },
            2: { cellWidth: 60 },
            3: { cellWidth: 58, halign: 'right' },
            4: { cellWidth: 92 },
            5: { cellWidth: 139 },
        },
        margin: { left: marginX, right: marginX, top: headerH + 10, bottom: footerH + 10 },
    });

    y = doc.lastAutoTable.finalY + 16;
    doc.autoTable({
        startY: y,
        head: [
            [{ content: 'Status History', colSpan: 5, styles: { fillColor: [255,255,255], textColor: ink, halign: 'left', fontStyle: 'bold' } }],
            ['Order', 'From', 'To', 'Changed', 'Changed By'],
        ],
        body: payload.history.length
            ? payload.history
            : [[{ content: 'No status history found.', colSpan: 5, styles: { halign: 'center', textColor: muted, fontStyle: 'italic' } }]],
        theme: 'grid',
        styles: { font: 'helvetica', fontSize: 8.2, cellPadding: { top: 4.5, right: 5.5, bottom: 4.5, left: 5.5 }, overflow: 'linebreak', lineColor: border, lineWidth: 0.8 },
        headStyles: { fillColor: brand2, textColor: [255,255,255], fontStyle: 'bold', minCellHeight: 16 },
        columnStyles: {
            0: { cellWidth: 146 },
            1: { cellWidth: 52 },
            2: { cellWidth: 52 },
            3: { cellWidth: 105 },
            4: { cellWidth: 160 },
        },
        margin: { left: marginX, right: marginX, top: headerH + 10, bottom: footerH + 10 },
    });

    const safe = (s) => String(s || '')
        .trim()
        .replace(/[\\\/:*?"<>|]+/g, '-')   // windows-invalid
        .replace(/\s+/g, ' ')
        .slice(0, 80);

    const code = safe(payload.customer.customer_code || 'report');
    const name = safe(payload.customer.full_name || 'customer');
    const filename = `Customer-${code}-${name}.pdf`;
    doc.save(filename);
}

document.getElementById('btnDownload').addEventListener('click', generateAndDownload);
setTimeout(generateAndDownload, 250);
</script>
</body>
</html>

