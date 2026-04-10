<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();
ensurePaymentTypeEnum();

function isValidDateInput(?string $value): bool
{
    if ($value === null || $value === '') {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
}

function csvOut(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$period = (string)($_GET['period'] ?? '30');
$allowedPeriods = ['7', '30', '90', '365', 'all'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = '30';
}

$today = date('Y-m-d');
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$orderStatus = (string)($_GET['order_status'] ?? 'all');
$paymentTypeFilter = (string)($_GET['payment_type'] ?? 'all');
$expenseCategoryFilter = trim((string)($_GET['expense_category'] ?? 'all'));
$exportType = trim((string)($_GET['export'] ?? ''));

if (!isValidDateInput($fromDate)) {
    $fromDate = '';
}
if (!isValidDateInput($toDate)) {
    $toDate = '';
}
if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$allowedStatuses = ['all', 'Order', 'Cutting', 'Stitching', 'Ready', 'Delivered'];
if (!in_array($orderStatus, $allowedStatuses, true)) {
    $orderStatus = 'all';
}
$allowedPaymentTypes = array_merge(['all'], array_keys(paymentMethodOptions(true)));
if (!in_array($paymentTypeFilter, $allowedPaymentTypes, true)) {
    $paymentTypeFilter = 'all';
}

$startDate = '';
$endDate = '';
$dateLabelMap = [
    '7' => 'Last 7 days',
    '30' => 'Last 30 days',
    '90' => 'Last 90 days',
    '365' => 'Last 365 days',
    'all' => 'All time',
];
$periodLabel = $dateLabelMap[$period] ?? 'Custom';

if ($fromDate !== '' || $toDate !== '') {
    $startDate = $fromDate !== '' ? $fromDate : '2000-01-01';
    $endDate = $toDate !== '' ? $toDate : $today;
    $periodLabel = 'Custom: ' . $startDate . ' to ' . $endDate;
} elseif ($period !== 'all') {
    $days = (int)$period;
    $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $endDate = $today;
}

$expenseCategories = db()->query('SELECT DISTINCT category FROM expenses ORDER BY category ASC')->fetchAll(PDO::FETCH_COLUMN);
if ($expenseCategoryFilter !== 'all' && !in_array($expenseCategoryFilter, $expenseCategories, true)) {
    $expenseCategoryFilter = 'all';
}

$paymentConditions = [];
$paymentParams = [];
if ($startDate !== '') {
    $paymentConditions[] = 'payment_date BETWEEN ? AND ?';
    $paymentParams[] = $startDate;
    $paymentParams[] = $endDate;
}
if ($paymentTypeFilter !== 'all') {
    $paymentConditions[] = 'payment_type = ?';
    $paymentParams[] = $paymentTypeFilter;
}
$paymentWhere = $paymentConditions ? (' WHERE ' . implode(' AND ', $paymentConditions)) : '';

$expenseConditions = [];
$expenseParams = [];
if ($startDate !== '') {
    $expenseConditions[] = 'expense_date BETWEEN ? AND ?';
    $expenseParams[] = $startDate;
    $expenseParams[] = $endDate;
}
if ($expenseCategoryFilter !== 'all') {
    $expenseConditions[] = 'category = ?';
    $expenseParams[] = $expenseCategoryFilter;
}
$expenseWhere = $expenseConditions ? (' WHERE ' . implode(' AND ', $expenseConditions)) : '';

$orderConditions = [];
$orderParams = [];
if ($startDate !== '') {
    $orderConditions[] = 'DATE(created_at) BETWEEN ? AND ?';
    $orderParams[] = $startDate;
    $orderParams[] = $endDate;
}
if ($orderStatus !== 'all') {
    $orderConditions[] = 'current_status = ?';
    $orderParams[] = $orderStatus;
}
$orderWhere = $orderConditions ? (' WHERE ' . implode(' AND ', $orderConditions)) : '';

$stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments' . $paymentWhere);
$stmt->execute($paymentParams);
$salesInRange = (float)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses' . $expenseWhere);
$stmt->execute($expenseParams);
$expenseInRange = (float)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM orders' . $orderWhere);
$stmt->execute($orderParams);
$ordersInRange = (int)$stmt->fetchColumn();

$deliveredWhere = $orderConditions;
$deliveredParams = $orderParams;
if ($orderStatus === 'all') {
    $deliveredWhere[] = "current_status = 'Delivered'";
} else {
    if ($orderStatus !== 'Delivered') {
        $deliveredWhere[] = "1=0";
    }
}
$stmt = db()->prepare('SELECT COUNT(*) FROM orders' . ($deliveredWhere ? (' WHERE ' . implode(' AND ', $deliveredWhere)) : ''));
$stmt->execute($deliveredParams);
$deliveredInRange = (int)$stmt->fetchColumn();

$outstandingAll = (float)db()->query('SELECT COALESCE(SUM(balance_amount), 0) FROM orders')->fetchColumn();
$netInRange = $salesInRange - $expenseInRange;
$deliveryRate = $ordersInRange > 0 ? round(($deliveredInRange / $ordersInRange) * 100, 1) : 0;

$monthlyRows = db()->query(
    "SELECT DATE_FORMAT(d.month_date, '%Y-%m') AS ym,
            DATE_FORMAT(d.month_date, '%b %Y') AS label,
            COALESCE(p.total_sales, 0) AS sales,
            COALESCE(e.total_expense, 0) AS expense
     FROM (
         SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL seq.n MONTH), '%Y-%m-01') AS month_date
         FROM (
             SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
             SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
         ) AS seq
     ) AS d
     LEFT JOIN (
         SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym, SUM(amount) AS total_sales
         FROM payments
         GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
     ) AS p ON p.ym = DATE_FORMAT(d.month_date, '%Y-%m')
     LEFT JOIN (
         SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS total_expense
         FROM expenses
         GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
     ) AS e ON e.ym = DATE_FORMAT(d.month_date, '%Y-%m')
     ORDER BY d.month_date ASC"
)->fetchAll();

$monthlyLabels = [];
$monthlySales = [];
$monthlyExpenses = [];
foreach ($monthlyRows as $row) {
    $monthlyLabels[] = (string)$row['label'];
    $monthlySales[] = (float)$row['sales'];
    $monthlyExpenses[] = (float)$row['expense'];
}

$statusStmt = db()->prepare(
    "SELECT current_status, COUNT(*) AS total
     FROM orders " . ($orderWhere ?: '') . "
     GROUP BY current_status
     ORDER BY FIELD(current_status, 'Order', 'Cutting', 'Stitching', 'Ready', 'Delivered')"
);
$statusStmt->execute($orderParams);
$statusRows = $statusStmt->fetchAll();
$statusLabels = [];
$statusCounts = [];
foreach ($statusRows as $row) {
    $statusLabels[] = (string)$row['current_status'];
    $statusCounts[] = (int)$row['total'];
}

$paymentTypeSql = "SELECT payment_type, COALESCE(SUM(amount), 0) AS total
                   FROM payments" . ($startDate !== '' ? ' WHERE payment_date BETWEEN ? AND ?' : '') . "
                   GROUP BY payment_type
                   ORDER BY FIELD(payment_type, 'cash', 'bank', 'easypaisa', 'jazzcash', 'card', 'other', 'advance', 'partial', 'final')";
$stmt = db()->prepare($paymentTypeSql);
$stmt->execute($startDate !== '' ? [$startDate, $endDate] : []);
$paymentTypeRows = $stmt->fetchAll();
$paymentTypeLabels = [];
$paymentTypeTotals = [];
foreach ($paymentTypeRows as $row) {
    $paymentTypeLabels[] = paymentTypeLabel((string)$row['payment_type']);
    $paymentTypeTotals[] = (float)$row['total'];
}

$expenseCategorySql = "SELECT category, COALESCE(SUM(amount), 0) AS total
                       FROM expenses" . ($startDate !== '' ? ' WHERE expense_date BETWEEN ? AND ?' : '') . "
                       GROUP BY category
                       ORDER BY total DESC
                       LIMIT 6";
$stmt = db()->prepare($expenseCategorySql);
$stmt->execute($startDate !== '' ? [$startDate, $endDate] : []);
$expenseCategoryRows = $stmt->fetchAll();
$expenseCategoryLabels = [];
$expenseCategoryTotals = [];
foreach ($expenseCategoryRows as $row) {
    $expenseCategoryLabels[] = (string)$row['category'];
    $expenseCategoryTotals[] = (float)$row['total'];
}

$topCustomersSql = "SELECT c.customer_code, c.full_name, c.phone, COALESCE(SUM(p.amount), 0) AS paid_total, COUNT(p.id) AS payments_count
                    FROM customers c
                    LEFT JOIN payments p ON p.customer_id = c.id" . ($startDate !== '' ? ' AND p.payment_date BETWEEN ? AND ?' : '') . ($paymentTypeFilter !== 'all' ? ' AND p.payment_type = ?' : '') . "
                    GROUP BY c.id, c.customer_code, c.full_name, c.phone
                    HAVING paid_total > 0
                    ORDER BY paid_total DESC
                    LIMIT 8";
$stmt = db()->prepare($topCustomersSql);
$topCustomersParams = [];
if ($startDate !== '') {
    $topCustomersParams[] = $startDate;
    $topCustomersParams[] = $endDate;
}
if ($paymentTypeFilter !== 'all') {
    $topCustomersParams[] = $paymentTypeFilter;
}
$stmt->execute($topCustomersParams);
$topCustomers = $stmt->fetchAll();

$upcomingSql = "SELECT o.order_code, c.full_name, o.delivery_date, o.current_status, o.balance_amount
     FROM orders o
     INNER JOIN customers c ON c.id = o.customer_id
     WHERE o.current_status <> 'Delivered'" . ($orderStatus !== 'all' ? ' AND o.current_status = ?' : '') . "
     ORDER BY o.delivery_date ASC
     LIMIT 10";
$stmt = db()->prepare($upcomingSql);
$stmt->execute($orderStatus !== 'all' ? [$orderStatus] : []);
$upcomingOrders = $stmt->fetchAll();

$summaryRows = [
    ['Sales', number_format($salesInRange, 2, '.', '')],
    ['Expenses', number_format($expenseInRange, 2, '.', '')],
    ['Net', number_format($netInRange, 2, '.', '')],
    ['Total Orders', (string)$ordersInRange],
    ['Delivered Orders', (string)$deliveredInRange],
    ['Delivery Rate %', number_format($deliveryRate, 1, '.', '')],
    ['Outstanding Dues (All Time)', number_format($outstandingAll, 2, '.', '')],
];

if ($exportType !== '') {
    $suffix = date('Ymd_His');
    if ($exportType === 'summary') {
        csvOut('reports_summary_' . $suffix . '.csv', ['Metric', 'Value'], $summaryRows);
    }
    if ($exportType === 'top_customers') {
        $rows = [];
        foreach ($topCustomers as $c) {
            $rows[] = [$c['customer_code'], $c['full_name'], $c['phone'], (int)$c['payments_count'], number_format((float)$c['paid_total'], 2, '.', '')];
        }
        csvOut('reports_top_customers_' . $suffix . '.csv', ['Customer Code', 'Customer Name', 'Phone', 'Payments', 'Collected'], $rows);
    }
    if ($exportType === 'pending_orders') {
        $rows = [];
        foreach ($upcomingOrders as $o) {
            $rows[] = [$o['order_code'], $o['full_name'], $o['delivery_date'], $o['current_status'], number_format((float)$o['balance_amount'], 2, '.', '')];
        }
        csvOut('reports_pending_orders_' . $suffix . '.csv', ['Order Code', 'Customer', 'Delivery Date', 'Status', 'Balance'], $rows);
    }
    if ($exportType === 'monthly_financial') {
        $rows = [];
        foreach ($monthlyRows as $m) {
            $rows[] = [$m['label'], number_format((float)$m['sales'], 2, '.', ''), number_format((float)$m['expense'], 2, '.', '')];
        }
        csvOut('reports_monthly_financial_' . $suffix . '.csv', ['Month', 'Sales', 'Expenses'], $rows);
    }
    if ($exportType === 'payment_types') {
        $rows = [];
        foreach ($paymentTypeRows as $r) {
            $rows[] = [paymentTypeLabel((string)$r['payment_type']), number_format((float)$r['total'], 2, '.', '')];
        }
        csvOut('reports_payment_types_' . $suffix . '.csv', ['Payment Type', 'Total Amount'], $rows);
    }
    if ($exportType === 'expense_categories') {
        $rows = [];
        foreach ($expenseCategoryRows as $r) {
            $rows[] = [(string)$r['category'], number_format((float)$r['total'], 2, '.', '')];
        }
        csvOut('reports_expense_categories_' . $suffix . '.csv', ['Category', 'Total Amount'], $rows);
    }
    if ($exportType === 'status_breakdown') {
        $rows = [];
        foreach ($statusRows as $r) {
            $rows[] = [(string)$r['current_status'], (int)$r['total']];
        }
        csvOut('reports_status_breakdown_' . $suffix . '.csv', ['Status', 'Orders'], $rows);
    }
}

$baseParams = [
    'period' => $period,
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'order_status' => $orderStatus,
    'payment_type' => $paymentTypeFilter,
    'expense_category' => $expenseCategoryFilter,
];
$queryBuild = static function (array $params): string {
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $clean[$k] = $v;
        }
    }
    return '?' . http_build_query($clean);
};
$urlWith = static function (array $overrides) use ($baseParams, $queryBuild): string {
    return $queryBuild(array_merge($baseParams, $overrides));
};

$pageTitle = 'Reports';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="reports-hero card shadow-sm mb-3">
    <div class="card-body d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
            <h1 class="h4 mb-1">Business Reports</h1>
            <p class="text-muted mb-0">Track sales, expenses, customer collections, and order performance in one place.</p>
        </div>
        <form method="get" class="reports-filters row g-2 align-items-end">
            <div class="col-6 col-md-3 col-lg-2">
                <label for="period" class="small text-muted fw-semibold mb-1">Period</label>
                <select name="period" id="period" class="form-select form-select-sm">
                    <option value="7" <?= $period === '7' ? 'selected' : '' ?>>7 days</option>
                    <option value="30" <?= $period === '30' ? 'selected' : '' ?>>30 days</option>
                    <option value="90" <?= $period === '90' ? 'selected' : '' ?>>90 days</option>
                    <option value="365" <?= $period === '365' ? 'selected' : '' ?>>365 days</option>
                    <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="small text-muted fw-semibold mb-1">From</label>
                <input type="date" name="from_date" value="<?= e($fromDate) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="small text-muted fw-semibold mb-1">To</label>
                <input type="date" name="to_date" value="<?= e($toDate) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="small text-muted fw-semibold mb-1">Order Status</label>
                <select name="order_status" class="form-select form-select-sm">
                    <?php foreach ($allowedStatuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= $orderStatus === $st ? 'selected' : '' ?>><?= e($st === 'all' ? 'All' : $st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="small text-muted fw-semibold mb-1">Payment Type</label>
                <select name="payment_type" class="form-select form-select-sm">
                    <option value="all" <?= $paymentTypeFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach (paymentMethodOptions(true) as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $paymentTypeFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="small text-muted fw-semibold mb-1">Expense Category</label>
                <select name="expense_category" class="form-select form-select-sm">
                    <option value="all">All</option>
                    <?php foreach ($expenseCategories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $expenseCategoryFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">Apply Filters</button>
                <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                <a href="<?= $urlWith(['export' => 'summary']) ?>" class="btn btn-sm btn-outline-dark">Export Summary CSV</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3">
        <div class="card metric-card reports-kpi-card h-100">
            <div class="card-body">
                <small>Sales (<?= e($periodLabel) ?>)</small>
                <h4>Rs <?= number_format($salesInRange, 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card metric-card reports-kpi-card h-100">
            <div class="card-body">
                <small>Expenses (<?= e($periodLabel) ?>)</small>
                <h4>Rs <?= number_format($expenseInRange, 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card metric-card reports-kpi-card h-100">
            <div class="card-body">
                <small>Net (Sales - Expenses)</small>
                <h4 class="<?= $netInRange >= 0 ? 'text-success' : 'text-danger' ?>">Rs <?= number_format($netInRange, 0) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card metric-card reports-kpi-card h-100">
            <div class="card-body">
                <small>Delivery Rate (<?= e($periodLabel) ?>)</small>
                <h4><?= number_format($deliveryRate, 1) ?>%</h4>
                <div class="text-muted small"><?= $deliveredInRange ?> delivered of <?= $ordersInRange ?> orders</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">6-Month Sales vs Expenses</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-light">Financial trend</span>
                        <a class="btn btn-sm btn-outline-secondary reports-export-btn" href="<?= $urlWith(['export' => 'monthly_financial']) ?>">Export CSV</a>
                    </div>
                </div>
                <div class="chart-box reports-chart-lg">
                    <canvas id="salesExpensesTrendChart" aria-label="Sales and expense trend chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Orders by Status</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-primary">Current</span>
                        <a class="btn btn-sm btn-outline-secondary reports-export-btn" href="<?= $urlWith(['export' => 'status_breakdown']) ?>">Export CSV</a>
                    </div>
                </div>
                <div class="chart-box reports-chart-sm">
                    <canvas id="ordersStatusChart" aria-label="Order status chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Payment Type Distribution</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-success"><?= e($periodLabel) ?></span>
                        <a class="btn btn-sm btn-outline-secondary reports-export-btn" href="<?= $urlWith(['export' => 'payment_types']) ?>">Export CSV</a>
                    </div>
                </div>
                <div class="chart-box reports-chart-sm">
                    <canvas id="paymentTypeChart" aria-label="Payment type distribution chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Top Expense Categories</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-warning"><?= e($periodLabel) ?></span>
                        <a class="btn btn-sm btn-outline-secondary reports-export-btn" href="<?= $urlWith(['export' => 'expense_categories']) ?>">Export CSV</a>
                    </div>
                </div>
                <div class="chart-box reports-chart-sm">
                    <canvas id="expenseCategoryChart" aria-label="Expense categories chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Top Customers by Collections</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-light"><?= e($periodLabel) ?></span>
                        <a class="btn btn-sm btn-outline-secondary reports-export-btn" href="<?= $urlWith(['export' => 'top_customers']) ?>">Export CSV</a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th class="text-end">Payments</th>
                            <th class="text-end">Collected</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topCustomers as $c): ?>
                            <tr>
                                <td><strong><?= e($c['full_name']) ?></strong><div class="text-muted small"><?= e($c['customer_code']) ?></div></td>
                                <td><?= e($c['phone']) ?></td>
                                <td class="text-end"><?= (int)$c['payments_count'] ?></td>
                                <td class="text-end">Rs <?= number_format((float)$c['paid_total'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topCustomers): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No customer payment data in this period.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Upcoming / Pending Deliveries</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-danger">Action needed</span>
                        <a class="btn btn-sm btn-outline-secondary reports-export-btn" href="<?= $urlWith(['export' => 'pending_orders']) ?>">Export CSV</a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Delivery</th>
                            <th>Status</th>
                            <th class="text-end">Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($upcomingOrders as $o): ?>
                            <tr>
                                <td><?= e($o['order_code']) ?></td>
                                <td><?= e($o['full_name']) ?></td>
                                <td><?= e($o['delivery_date']) ?></td>
                                <td><span class="badge text-bg-light"><?= e($o['current_status']) ?></span></td>
                                <td class="text-end">Rs <?= number_format((float)$o['balance_amount'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$upcomingOrders): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No pending deliveries.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-3 reports-mini-summary">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-4"><div class="p-2 rounded bg-light border h-100"><small class="text-muted d-block">Outstanding Dues (All time)</small><strong>Rs <?= number_format($outstandingAll, 0) ?></strong></div></div>
            <div class="col-md-4"><div class="p-2 rounded bg-light border h-100"><small class="text-muted d-block">Total Orders (<?= e($periodLabel) ?>)</small><strong><?= number_format($ordersInRange) ?></strong></div></div>
            <div class="col-md-4"><div class="p-2 rounded bg-light border h-100"><small class="text-muted d-block">Delivered Orders (<?= e($periodLabel) ?>)</small><strong><?= number_format($deliveredInRange) ?></strong></div></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    (function () {
        const monthlyLabels = <?= json_encode($monthlyLabels, JSON_UNESCAPED_SLASHES) ?>;
        const monthlySales = <?= json_encode($monthlySales, JSON_UNESCAPED_SLASHES) ?>;
        const monthlyExpenses = <?= json_encode($monthlyExpenses, JSON_UNESCAPED_SLASHES) ?>;
        const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_SLASHES) ?>;
        const statusCounts = <?= json_encode($statusCounts, JSON_UNESCAPED_SLASHES) ?>;
        const paymentTypeLabels = <?= json_encode($paymentTypeLabels, JSON_UNESCAPED_SLASHES) ?>;
        const paymentTypeTotals = <?= json_encode($paymentTypeTotals, JSON_UNESCAPED_SLASHES) ?>;
        const expenseCategoryLabels = <?= json_encode($expenseCategoryLabels, JSON_UNESCAPED_SLASHES) ?>;
        const expenseCategoryTotals = <?= json_encode($expenseCategoryTotals, JSON_UNESCAPED_SLASHES) ?>;

        const tickColor = '#64748b';
        const gridColor = 'rgba(148, 163, 184, 0.3)';

        const trendEl = document.getElementById('salesExpensesTrendChart');
        if (trendEl) {
            new Chart(trendEl, {
                type: 'line',
                data: {
                    labels: monthlyLabels,
                    datasets: [
                        {
                            label: 'Sales',
                            data: monthlySales,
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34, 197, 94, 0.14)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2
                        },
                        {
                            label: 'Expenses',
                            data: monthlyExpenses,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { color: tickColor } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tickColor } },
                        y: { grid: { color: gridColor }, ticks: { color: tickColor } }
                    }
                }
            });
        }

        const statusEl = document.getElementById('ordersStatusChart');
        if (statusEl) {
            new Chart(statusEl, {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: ['#4a5bb9', '#0ea5e9', '#a855f7', '#f59e0b', '#22c55e'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, color: tickColor } }
                    }
                }
            });
        }

        const paymentTypeEl = document.getElementById('paymentTypeChart');
        if (paymentTypeEl) {
            new Chart(paymentTypeEl, {
                type: 'pie',
                data: {
                    labels: paymentTypeLabels,
                    datasets: [{
                        data: paymentTypeTotals,
                        backgroundColor: ['#3b82f6', '#f59e0b', '#16a34a'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: tickColor } } }
                }
            });
        }

        const expenseCategoryEl = document.getElementById('expenseCategoryChart');
        if (expenseCategoryEl) {
            new Chart(expenseCategoryEl, {
                type: 'bar',
                data: {
                    labels: expenseCategoryLabels,
                    datasets: [{
                        label: 'Amount',
                        data: expenseCategoryTotals,
                        backgroundColor: '#f59e0b',
                        borderRadius: 6,
                        maxBarThickness: 26
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tickColor } },
                        y: { grid: { color: gridColor }, ticks: { color: tickColor } }
                    }
                }
            });
        }
    })();
</script>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

