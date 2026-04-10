<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensureOrderQuantityColumn();
ensureAdvancePaymentsBackfill();

$orderId = (int)($_GET['id'] ?? 0);
$view = (string)($_GET['view'] ?? '');
$customerView = $view === 'customer';
$tailorView = $view === 'tailor';

$orderStmt = db()->prepare(
    "SELECT o.*, c.customer_code, c.full_name AS customer_name, c.phone, c.address, u.full_name AS created_by_name
     FROM orders o
     INNER JOIN customers c ON c.id = o.customer_id
     LEFT JOIN users u ON u.id = o.created_by
     WHERE o.id = ?"
);
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();
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

$siteTitle = appSetting('site_title', APP_NAME);
$siteLogo = appSetting('site_logo', '');
$companyAddress = appSetting('contact_address', '');
$companyPhone = appSetting('contact_phone', '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Details PDF</title>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.3/dist/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
<script>
const payload = <?= json_encode([
    'customerView' => $customerView,
    'tailorView' => $tailorView,
    'generatedAt' => date('Y-m-d H:i'),
    'company' => [
        'title' => $siteTitle,
        'logo' => $siteLogo,
        'address' => $companyAddress,
        'phone' => $companyPhone,
    ],
    'order' => [
        'id' => (int)$order['id'],
        'order_code' => (string)($order['order_code'] ?? ''),
        'quantity' => (int)($order['quantity'] ?? 1),
        'tracking_code' => (string)($order['tracking_code'] ?? ''),
        'status' => (string)($order['current_status'] ?? ''),
        'delivery_date' => (string)($order['delivery_date'] ?? ''),
        'total' => number_format((float)($order['total_amount'] ?? 0), 0),
        'paid' => number_format((float)($order['paid_amount'] ?? 0), 0),
        'balance' => number_format((float)($order['balance_amount'] ?? 0), 0),
        'created_at' => (string)($order['created_at'] ?? ''),
        'created_by' => (string)($order['created_by_name'] ?? '-'),
        'instructions' => (string)($order['special_instructions'] ?? ''),
    ],
    'customer' => [
        'name' => (string)($order['customer_name'] ?? ''),
        'code' => (string)($order['customer_code'] ?? ''),
        'phone' => (string)($order['phone'] ?? ''),
        'address' => (string)($order['address'] ?? ''),
    ],
    'kameez' => [
        ['Length', (string)($kameez['length'] ?? '-')],
        ['Shoulder', (string)($kameez['shoulder'] ?? '-')],
        ['Chest', (string)($kameez['chest'] ?? '-')],
        ['Waist', (string)($kameez['waist'] ?? '-')],
        ['Hip', (string)($kameez['hip'] ?? '-')],
        ['Sleeve Length', (string)($kameez['sleeve_length'] ?? '-')],
        ['Arm Round', (string)($kameez['arm_round'] ?? '-')],
        ['Cuff', (string)($kameez['cuff'] ?? '-')],
        ['Neck', (string)($kameez['neck'] ?? '-')],
    ],
    'shalwar' => [
        ['Length', (string)($shalwar['length'] ?? '-')],
        ['Waist', (string)($shalwar['waist'] ?? '-')],
        ['Hip', (string)($shalwar['hip'] ?? '-')],
        ['Thigh', (string)($shalwar['thigh'] ?? '-')],
        ['Knee', (string)($shalwar['knee'] ?? '-')],
        ['Bottom', (string)($shalwar['bottom'] ?? '-')],
    ],
    'style' => [
        ['Collar Type', (string)($style['collar_type'] ?? '-')],
        ['Pocket', (string)($style['pocket'] ?? '-')],
        ['Cuff Style', (string)($style['cuff_style'] ?? '-')],
        ['Front Style', (string)($style['front_style'] ?? '-')],
        ['Style Notes', (string)($style['special_instructions'] ?? '-')],
    ],
    'payments' => array_map(static function ($p) {
        return [
            (string)($p['payment_date'] ?? ''),
            (string)($p['payment_type'] ?? ''),
            'Rs ' . number_format((float)($p['amount'] ?? 0), 0),
            (string)($p['notes'] ?? ''),
            (string)($p['posted_by_name'] ?? '-'),
        ];
    }, $payments),
    'history' => array_map(static function ($h) {
        return [
            (string)($h['old_status'] ?? '-'),
            (string)($h['new_status'] ?? ''),
            (string)($h['changed_at'] ?? ''),
            (string)($h['changed_by_name'] ?? '-'),
        ];
    }, $history),
], JSON_UNESCAPED_UNICODE) ?>;

async function loadImageAsDataUrl(url) {
    if (!url) return null;
    try {
        let normalized = url;
        if (normalized.startsWith('/')) {
            normalized = window.location.origin + normalized;
        }
        const res = await fetch(normalized, { cache: 'no-store' });
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

function sectionTitle(doc, text, y, colors) {
    doc.setFillColor(...colors.primary);
    doc.rect(36, y - 12, 523, 20, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10.5);
    doc.text(text, 46, y + 1);
    doc.setTextColor(...colors.text);
}

function sectionTitleAt(doc, text, x, y, width, colors) {
    doc.setFillColor(...colors.primary);
    doc.rect(x, y - 12, width, 20, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.text(text, x + 10, y + 1);
    doc.setTextColor(...colors.text);
}

async function generatePDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'pt', format: 'a4' });
    const logoData = await loadImageAsDataUrl(payload.company.logo || '');

    const colors = {
        bg: [247, 250, 255],
        card: [255, 255, 255],
        primary: [24, 58, 121],       // navy
        accent: [220, 124, 78],       // coral
        text: [24, 37, 68],
        muted: [107, 120, 145],
        line: [213, 221, 235],
        zebra: [250, 252, 255]
    };

    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();
    const title = payload.tailorView
        ? 'TAILOR MEASUREMENT COPY'
        : (payload.customerView ? 'ORDER SUMMARY' : 'ORDER CONFIRMATION');

    function drawHeader(withBackground = true) {
        if (withBackground) {
            doc.setFillColor(...colors.bg);
            doc.rect(0, 0, pageW, pageH, 'F');

            // Floating page card
            doc.setFillColor(...colors.card);
            doc.roundedRect(28, 16, pageW - 56, pageH - 38, 10, 10, 'F');
            doc.setDrawColor(...colors.line);
            doc.setLineWidth(0.7);
            doc.roundedRect(28, 16, pageW - 56, pageH - 38, 10, 10);
        }

        if (logoData) {
            try { doc.addImage(logoData, 'PNG', 38, 24, 42, 42); } catch {}
        }

        // Left company block
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.setTextColor(...colors.text);
        doc.text(payload.company.title || 'Company', 88, 42);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.setTextColor(...colors.muted);
        if (payload.company.address) doc.text(payload.company.address, 88, 54);
        if (payload.company.phone) doc.text(`Phone: ${payload.company.phone}`, 88, 66);

        // Right title block
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(...colors.primary);
        doc.setFontSize(14);
        doc.text(title, pageW - 40, 40, { align: 'right' });
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(...colors.text);
        doc.setFontSize(8.8);
        doc.text(`Order #: ${payload.order.order_code}`, pageW - 40, 54, { align: 'right' });
        doc.text(`Date: ${payload.generatedAt}`, pageW - 40, 66, { align: 'right' });

        // Status progress
        const statuses = ['Order', 'Cutting', 'Stitching', 'Ready', 'Delivered'];
        const current = Math.max(0, statuses.findIndex(s => s.toLowerCase() === (payload.order.status || '').toLowerCase()));
        const startX = 46;
        const endX = pageW - 48;
        const y = 88;
        const stepGap = (endX - startX) / (statuses.length - 1);
        doc.setDrawColor(...colors.line);
        doc.setLineWidth(3.5);
        doc.line(startX, y, endX, y);
        doc.setDrawColor(...colors.primary);
        doc.line(startX, y, startX + (stepGap * current), y);

        statuses.forEach((s, i) => {
            const x = startX + (stepGap * i);
            const active = i <= current;
            doc.setFillColor(...(active ? colors.primary : [223, 231, 243]));
            doc.circle(x, y, 4, 'F');
            doc.setFont('helvetica', active ? 'bold' : 'normal');
            doc.setFontSize(7.4);
            doc.setTextColor(...(active ? colors.text : colors.muted));
            doc.text(s, x, y + 12, { align: 'center' });
        });

        doc.setDrawColor(...colors.line);
        doc.setLineWidth(0.9);
        doc.line(36, 104, pageW - 36, 104);
        doc.setTextColor(...colors.text);
    }

    function drawFooter() {
        const pageCount = doc.getNumberOfPages();
        const pageNo = doc.internal.getCurrentPageInfo().pageNumber;
        doc.setDrawColor(...colors.line);
        doc.setLineWidth(0.7);
        doc.line(36, pageH - 30, pageW - 36, pageH - 30);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.2);
        doc.setTextColor(...colors.muted);
        doc.text('Thank you for your order!', 42, pageH - 18);
        doc.text(`Support: ${payload.company.phone || '-'}`, 42, pageH - 9);
        doc.text(`Page ${pageNo} of ${pageCount}`, pageW - 40, pageH - 11, { align: 'right' });
    }

    drawHeader();

    sectionTitle(doc, 'Customer & Order Summary', 122, colors);
    doc.autoTable({
        startY: 130,
        head: [['Field', 'Value']],
        body: payload.tailorView
            ? [
                ['Order Code', payload.order.order_code || '-'],
                ['Tracking Code', payload.order.tracking_code || '-'],
                ['Quantity', String(payload.order.quantity || 1)],
                ['Customer Name', payload.customer.name || '-'],
                ['Customer Phone', payload.customer.phone || '-'],
                ['Delivery Date', payload.order.delivery_date || '-'],
                ['Order Status', payload.order.status || '-'],
                ['Special Instructions', payload.order.instructions || '-'],
            ]
            : [
                ['Customer Name', payload.customer.name || '-'],
                ['Customer Code', payload.customer.code || '-'],
                ['Customer Phone', payload.customer.phone || '-'],
                ['Customer Address', payload.customer.address || '-'],
                ['Order Code', payload.order.order_code || '-'],
                ['Tracking Code', payload.order.tracking_code || '-'],
                ['Quantity', String(payload.order.quantity || 1)],
                ['Status', payload.order.status || '-'],
                ['Delivery Date', payload.order.delivery_date || '-'],
                ['Total Amount', `Rs ${payload.order.total}`],
                ['Paid Amount', `Rs ${payload.order.paid}`],
                ['Balance Amount', `Rs ${payload.order.balance}`],
                ['Created At', payload.order.created_at || '-'],
                ['Created By', payload.order.created_by || '-'],
                ['Special Instructions', payload.order.instructions || '-'],
            ],
        theme: 'grid',
        styles: { fontSize: 8.6, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text },
        headStyles: { fillColor: colors.primary, textColor: [255, 255, 255], minCellHeight: 18 },
        alternateRowStyles: { fillColor: colors.zebra },
        columnStyles: { 0: { cellWidth: 168, fontStyle: 'bold' }, 1: { cellWidth: 355 } },
        margin: { left: 36, right: 36, top: 110, bottom: 30 },
        didDrawPage: function () {
            drawHeader(false);
            drawFooter();
        }
    });

    let y = doc.lastAutoTable.finalY + 14;

    if (!payload.tailorView) {
        sectionTitle(doc, 'Order Items', y, colors);
        doc.autoTable({
            startY: y + 8,
            head: [['Item', 'Quantity', 'Unit Price', 'Total']],
            body: [[
                `Tailoring Service (${payload.order.order_code})`,
                String(payload.order.quantity || 1),
                `Rs ${(
                    (parseFloat(String(payload.order.total).replace(/,/g, '')) || 0) /
                    Math.max(1, parseInt(payload.order.quantity, 10) || 1)
                ).toFixed(2)}`,
                `Rs ${payload.order.total}`
            ]],
            theme: 'grid',
            styles: { fontSize: 8.6, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text },
            headStyles: { fillColor: colors.primary, textColor: [255, 255, 255], minCellHeight: 18 },
            alternateRowStyles: { fillColor: colors.zebra },
            columnStyles: {
                0: { cellWidth: 300 },
                1: { cellWidth: 70, halign: 'center' },
                2: { cellWidth: 76, halign: 'right' },
                3: { cellWidth: 77, halign: 'right' }
            },
            margin: { left: 36, right: 36, top: 110, bottom: 30 }
        });

        // totals box
        y = doc.lastAutoTable.finalY + 12;
        doc.setFillColor(251, 248, 243);
        doc.roundedRect(362, y, 197, 82, 5, 5, 'F');
        doc.setDrawColor(...colors.line);
        doc.roundedRect(362, y, 197, 82, 5, 5);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);
        doc.setTextColor(...colors.text);
        doc.text('Subtotal', 374, y + 20);
        doc.text(`Rs ${payload.order.total}`, 548, y + 20, { align: 'right' });
        doc.text('Shipping', 374, y + 38);
        doc.text('Rs 0', 548, y + 38, { align: 'right' });
        doc.text('Tax', 374, y + 56);
        doc.text('Rs 0', 548, y + 56, { align: 'right' });
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(...colors.accent);
        doc.text('Grand Total', 374, y + 74);
        doc.text(`Rs ${payload.order.total}`, 548, y + 74, { align: 'right' });
        doc.setTextColor(...colors.text);

        y = y + 104;
    }
    if (payload.tailorView) {
        const colGap = 12;
        const colLeft = 36;
        const colWidth = (523 - colGap) / 2;
        const colRight = colLeft + colWidth + colGap;
        const tableStartY = y + 8;

        sectionTitleAt(doc, 'Kameez Measurements', colLeft, y, colWidth, colors);
        doc.autoTable({
            startY: tableStartY,
            head: [['Measurement', 'Value']],
            body: payload.kameez,
            theme: 'grid',
            styles: { fontSize: 8.4, lineColor: colors.line, lineWidth: 0.45, cellPadding: 4.5, textColor: colors.text },
            headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
            alternateRowStyles: { fillColor: colors.zebra },
            tableWidth: colWidth,
            columnStyles: { 0: { cellWidth: colWidth * 0.66 }, 1: { cellWidth: colWidth * 0.34, halign: 'right' } },
            margin: { left: colLeft, right: pageW - (colLeft + colWidth), top: 110, bottom: 30 }
        });
        const leftFinalY = doc.lastAutoTable.finalY;

        sectionTitleAt(doc, 'Shalwar Measurements', colRight, y, colWidth, colors);
        doc.autoTable({
            startY: tableStartY,
            head: [['Measurement', 'Value']],
            body: payload.shalwar,
            theme: 'grid',
            styles: { fontSize: 8.4, lineColor: colors.line, lineWidth: 0.45, cellPadding: 4.5, textColor: colors.text },
            headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
            alternateRowStyles: { fillColor: colors.zebra },
            tableWidth: colWidth,
            columnStyles: { 0: { cellWidth: colWidth * 0.66 }, 1: { cellWidth: colWidth * 0.34, halign: 'right' } },
            margin: { left: colRight, right: pageW - (colRight + colWidth), top: 110, bottom: 30 }
        });
        const rightFinalY = doc.lastAutoTable.finalY;
        y = Math.max(leftFinalY, rightFinalY) + 14;
    } else {
        sectionTitle(doc, 'Kameez Measurements', y, colors);
        doc.autoTable({
            startY: y + 8,
            head: [['Measurement', 'Value']],
            body: payload.kameez,
            theme: 'grid',
            styles: { fontSize: 8.5, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text },
            headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
            alternateRowStyles: { fillColor: colors.zebra },
            margin: { left: 36, right: 36, top: 110, bottom: 30 }
        });

        y = doc.lastAutoTable.finalY + 14;
        sectionTitle(doc, 'Shalwar Measurements', y, colors);
        doc.autoTable({
            startY: y + 8,
            head: [['Measurement', 'Value']],
            body: payload.shalwar,
            theme: 'grid',
            styles: { fontSize: 8.5, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text },
            headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
            alternateRowStyles: { fillColor: colors.zebra },
            margin: { left: 36, right: 36, top: 110, bottom: 30 }
        });

        y = doc.lastAutoTable.finalY + 14;
    }

    sectionTitle(doc, 'Style Options', y, colors);
    doc.autoTable({
        startY: y + 8,
        head: [['Field', 'Value']],
        body: payload.style,
        theme: 'grid',
        styles: { fontSize: 8.5, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text },
        headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
        alternateRowStyles: { fillColor: colors.zebra },
        margin: { left: 36, right: 36, top: 110, bottom: 30 }
    });

    if (!payload.customerView && !payload.tailorView) {
        y = doc.lastAutoTable.finalY + 14;
        sectionTitle(doc, 'Payments', y, colors);
        doc.autoTable({
            startY: y + 8,
            head: [['Date', 'Type', 'Amount', 'Notes', 'Posted By']],
            body: payload.payments.length ? payload.payments : [['-', '-', '-', 'No payments posted.', '-']],
            theme: 'grid',
            styles: { fontSize: 8.5, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text },
            headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
            alternateRowStyles: { fillColor: colors.zebra },
            margin: { left: 36, right: 36, top: 110, bottom: 30 }
        });

        y = doc.lastAutoTable.finalY + 14;
        sectionTitle(doc, 'Status History', y, colors);
        doc.autoTable({
            startY: y + 8,
            head: [['From', 'To', 'Changed At', 'Changed By']],
            body: payload.history.length ? payload.history : [['-', '-', 'No status history.', '-']],
            theme: 'grid',
            styles: { fontSize: 8.5, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text },
            headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
            alternateRowStyles: { fillColor: colors.zebra },
            margin: { left: 36, right: 36, top: 110, bottom: 30 }
        });
    }

    const safe = (s) => String(s || '').replace(/[\\/:*?"<>|]+/g, '-').trim();
    const customerSafe = safe(payload.customer.name || 'Customer');
    const orderSafe = safe(payload.order.order_code);
    const fileName = payload.tailorView
        ? `Order-Tailor-Measurements-${customerSafe}-${orderSafe}.pdf`
        : (payload.customerView
            ? `Order-Customer-Copy-${customerSafe}-${orderSafe}.pdf`
            : `Order-Full-${customerSafe}-${orderSafe}.pdf`);

    // Download without navigating/closing the current tab.
    const blob = doc.output('blob');
    const blobUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = blobUrl;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(blobUrl), 1500);
}

generatePDF();
</script>
</body>
</html>

