<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();

$paymentId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT p.*, o.order_code, o.tracking_code, o.total_amount, o.paid_amount, o.balance_amount,
            c.customer_code, c.full_name AS customer_name, c.phone, c.address, u.full_name AS posted_by_name
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
$siteLogo = appSetting('site_logo', '');
$companyAddress = appSetting('contact_address', '');
$companyPhone = appSetting('contact_phone', '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Receipt PDF</title>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.3/dist/jspdf.plugin.autotable.min.js"></script>
</head>
<body>
<script>
const payload = <?= json_encode([
    'generatedAt' => date('Y-m-d H:i'),
    'company' => [
        'title' => $siteTitle,
        'logo' => $siteLogo,
        'address' => $companyAddress,
        'phone' => $companyPhone,
    ],
    'payment' => [
        'id' => (int)$payment['id'],
        'date' => (string)($payment['payment_date'] ?? ''),
        'type' => paymentTypeLabel((string)($payment['payment_type'] ?? '')),
        'amount' => number_format((float)($payment['amount'] ?? 0), 0),
        'notes' => trim((string)($payment['notes'] ?? '')),
        'posted_by' => (string)($payment['posted_by_name'] ?? '-'),
    ],
    'order' => [
        'code' => (string)($payment['order_code'] ?? ''),
        'tracking' => (string)($payment['tracking_code'] ?? ''),
        'total' => number_format((float)($payment['total_amount'] ?? 0), 0),
        'paid' => number_format((float)($payment['paid_amount'] ?? 0), 0),
        'balance' => number_format((float)($payment['balance_amount'] ?? 0), 0),
    ],
    'customer' => [
        'name' => (string)($payment['customer_name'] ?? ''),
        'code' => (string)($payment['customer_code'] ?? ''),
        'phone' => (string)($payment['phone'] ?? ''),
        'address' => (string)($payment['address'] ?? ''),
    ],
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

async function generatePDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'pt', format: 'a4' });
    const logoData = await loadImageAsDataUrl(payload.company.logo || '');
    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();

    const colors = {
        primary: [24, 58, 121],
        text: [24, 37, 68],
        muted: [107, 120, 145],
        line: [213, 221, 235],
        zebra: [250, 252, 255]
    };

    if (logoData) {
        try { doc.addImage(logoData, 'PNG', 42, 30, 36, 36); } catch {}
    }

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.setTextColor(...colors.primary);
    doc.text('PAYMENT RECEIPT', pageW - 42, 42, { align: 'right' });

    doc.setTextColor(...colors.text);
    doc.setFontSize(11);
    doc.text(payload.company.title || 'Company', 86, 42);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    if (payload.company.address) doc.text(payload.company.address, 86, 54);
    if (payload.company.phone) doc.text(`Phone: ${payload.company.phone}`, 86, 66);

    doc.setFontSize(8.8);
    doc.text(`Receipt #: ${payload.payment.id}`, pageW - 42, 56, { align: 'right' });
    doc.text(`Date: ${payload.generatedAt}`, pageW - 42, 68, { align: 'right' });

    doc.setDrawColor(...colors.line);
    doc.line(36, 88, pageW - 36, 88);

    doc.autoTable({
        startY: 98,
        head: [['Field', 'Value']],
        body: [
            ['Payment Information', ''],
            ['Receipt No', String(payload.payment.id || '-')],
            ['Payment Date', payload.payment.date || '-'],
            ['Payment Type', payload.payment.type || '-'],
            ['Amount', `Rs ${payload.payment.amount}`],
            ['Posted By', payload.payment.posted_by || '-'],
            ['Order Information', ''],
            ['Order Code', payload.order.code || '-'],
            ['Tracking Code', payload.order.tracking || '-'],
            ['Order Total', `Rs ${payload.order.total}`],
            ['Total Paid', `Rs ${payload.order.paid}`],
            ['Remaining Balance', `Rs ${payload.order.balance}`],
            ['Customer Information', ''],
            ['Customer Name', payload.customer.name || '-'],
            ['Customer Code', payload.customer.code || '-'],
            ['Customer Phone', payload.customer.phone || '-'],
            ['Customer Address', payload.customer.address || '-'],
            ['Notes', payload.payment.notes || '-'],
        ],
        theme: 'grid',
        styles: { fontSize: 9, lineColor: colors.line, lineWidth: 0.45, cellPadding: 5, textColor: colors.text, overflow: 'linebreak' },
        headStyles: { fillColor: colors.primary, textColor: [255, 255, 255] },
        alternateRowStyles: { fillColor: colors.zebra },
        columnStyles: { 0: { cellWidth: 175, fontStyle: 'bold' }, 1: { cellWidth: 348 } },
        margin: { left: 36, right: 36 },
        didParseCell: function (data) {
            if (data.section !== 'body') return;
            const raw = String(data.row.raw && data.row.raw[0] ? data.row.raw[0] : '');
            const isSection = raw === 'Payment Information' || raw === 'Order Information' || raw === 'Customer Information';
            if (isSection) {
                data.cell.styles.fillColor = colors.primary;
                data.cell.styles.textColor = [255, 255, 255];
                data.cell.styles.fontStyle = 'bold';
                if (data.column.index === 1) {
                    data.cell.text = '';
                }
            }
        }
    });

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.2);
    doc.setTextColor(...colors.muted);
    doc.text('Thank you for your payment.', 42, pageH - 20);
    doc.text(`Page 1 of 1`, pageW - 42, pageH - 20, { align: 'right' });

    const safe = (s) => String(s || '').replace(/[\\/:*?"<>|]+/g, '-').trim();
    const fileName = `Payment-Receipt-${safe(payload.payment.id)}-${safe(payload.order.code)}.pdf`;

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

