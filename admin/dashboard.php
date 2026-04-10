<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();

$metrics = [
    'customers' => (int)db()->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
    'orders' => (int)db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'ready' => (int)db()->query("SELECT COUNT(*) FROM orders WHERE current_status = 'Ready'")->fetchColumn(),
    'balance' => (float)db()->query('SELECT COALESCE(SUM(balance_amount), 0) FROM orders')->fetchColumn(),
];

$statusRows = db()->query(
    "SELECT current_status, COUNT(*) AS total
     FROM orders GROUP BY current_status ORDER BY FIELD(current_status,'Order','Cutting','Stitching','Ready','Delivered')"
)->fetchAll();

$statusLabels = [];
$statusCounts = [];
foreach ($statusRows as $row) {
    $statusLabels[] = (string)$row['current_status'];
    $statusCounts[] = (int)$row['total'];
}

// Last 7 days: payments and expenses
$paymentRows = db()->query(
    "SELECT DATE(payment_date) AS d, COALESCE(SUM(amount), 0) AS total
     FROM payments
     WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(payment_date)
     ORDER BY d ASC"
)->fetchAll();

$expenseRows = db()->query(
    "SELECT DATE(expense_date) AS d, COALESCE(SUM(amount), 0) AS total
     FROM expenses
     WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(expense_date)
     ORDER BY d ASC"
)->fetchAll();

$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[] = date('Y-m-d', strtotime("-{$i} day"));
}

$paymentsByDay = array_fill_keys($days, 0.0);
foreach ($paymentRows as $r) {
    $paymentsByDay[(string)$r['d']] = (float)$r['total'];
}

$expensesByDay = array_fill_keys($days, 0.0);
foreach ($expenseRows as $r) {
    $expensesByDay[(string)$r['d']] = (float)$r['total'];
}

$chartLabels = array_map(static fn($d) => date('D', strtotime($d)), $days);
$chartPayments = array_values($paymentsByDay);
$chartExpenses = array_values($expensesByDay);

$orderTrendRows = db()->query(
    "SELECT DATE(created_at) AS d, COUNT(*) AS total
     FROM orders
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(created_at)
     ORDER BY d ASC"
)->fetchAll();

$trendDays = [];
for ($i = 13; $i >= 0; $i--) {
    $trendDays[] = date('Y-m-d', strtotime("-{$i} day"));
}
$ordersByDay = array_fill_keys($trendDays, 0);
foreach ($orderTrendRows as $r) {
    $ordersByDay[(string)$r['d']] = (int)$r['total'];
}
$ordersTrendLabels = array_map(static fn($d) => date('M j', strtotime($d)), $trendDays);
$ordersTrendCounts = array_values($ordersByDay);

$pageTitle = 'Dashboard';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Dashboard</h1>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/customers.php" class="btn btn-primary btn-sm">Customers</a>
        <a href="<?= BASE_URL ?>/admin/orders.php" class="btn btn-primary btn-sm">Orders</a>
        <a href="<?= BASE_URL ?>/admin/expenses.php" class="btn btn-outline-secondary btn-sm">Expenses</a>
        <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-outline-dark btn-sm">Reports</a>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3"><div class="card metric-card"><div class="card-body text-center"><small class="text-muted">Customers</small><h3 class="mb-0"><?= $metrics['customers'] ?></h3></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card metric-card"><div class="card-body text-center"><small class="text-muted">Total Orders</small><h3 class="mb-0"><?= $metrics['orders'] ?></h3></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card metric-card"><div class="card-body text-center"><small class="text-muted">Ready Orders</small><h3 class="mb-0"><?= $metrics['ready'] ?></h3></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card metric-card"><div class="card-body text-center"><small class="text-muted">Outstanding</small><h3 class="mb-0">Rs <?= number_format($metrics['balance'], 0) ?></h3></div></div></div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Orders Trend</h2>
                    <span class="badge text-bg-primary">Last 14 days</span>
                </div>
                <div class="chart-box">
                    <canvas id="ordersTrendChart" aria-label="Orders trend line chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Orders by Status</h2>
                    <span class="badge text-bg-light">Today</span>
                </div>
                <div class="chart-box">
                    <canvas id="statusChart" aria-label="Orders status chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Payments Trend</h2>
                    <span class="badge text-bg-success">Last 7 days</span>
                </div>
                <div class="chart-box">
                    <canvas id="paymentsChart" aria-label="Payments trend chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Expenses Trend</h2>
                    <span class="badge text-bg-warning">Last 7 days</span>
                </div>
                <div class="chart-box">
                    <canvas id="expensesChart" aria-label="Expenses trend chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Tracking Pipeline</h2>
        <div class="row row-cols-1 row-cols-md-5 g-2">
            <?php foreach ($statusRows as $row): ?>
                <div class="col"><div class="border rounded p-2 text-center"><strong><?= e($row['current_status']) ?></strong><br><?= (int)$row['total'] ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    (function () {
        const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_SLASHES) ?>;
        const statusCounts = <?= json_encode($statusCounts, JSON_UNESCAPED_SLASHES) ?>;
        const trendLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_SLASHES) ?>;
        const payments = <?= json_encode($chartPayments, JSON_UNESCAPED_SLASHES) ?>;
        const expenses = <?= json_encode($chartExpenses, JSON_UNESCAPED_SLASHES) ?>;
        const ordersTrendLabels = <?= json_encode($ordersTrendLabels, JSON_UNESCAPED_SLASHES) ?>;
        const ordersTrendCounts = <?= json_encode($ordersTrendCounts, JSON_UNESCAPED_SLASHES) ?>;

        const gridColor = 'rgba(148, 163, 184, 0.35)';
        const tickColor = '#64748b';

        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: ['#4a5bb9', '#22c55e', '#06b6d4', '#f59e0b', '#ef4444'],
                        borderWidth: 0,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, color: tickColor } },
                        tooltip: { enabled: true },
                    },
                    cutout: '65%',
                }
            });
        }

        const makeTrend = (el, label, data, color) => {
            if (!el) return;
            new Chart(el, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label,
                        data,
                        borderColor: color,
                        backgroundColor: color + '22',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2,
                        pointHoverRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tickColor } },
                        y: { grid: { color: gridColor }, ticks: { color: tickColor } },
                    }
                }
            });
        };

        makeTrend(document.getElementById('paymentsChart'), 'Payments', payments, '#22c55e');
        makeTrend(document.getElementById('expensesChart'), 'Expenses', expenses, '#f59e0b');

        const ordersEl = document.getElementById('ordersTrendChart');
        if (ordersEl) {
            const octx = ordersEl.getContext('2d');
            const grad = octx.createLinearGradient(0, 0, 0, ordersEl.height || 220);
            grad.addColorStop(0, 'rgba(74, 91, 185, 0.35)');
            grad.addColorStop(1, 'rgba(74, 91, 185, 0.02)');
            new Chart(ordersEl, {
                type: 'line',
                data: {
                    labels: ordersTrendLabels,
                    datasets: [{
                        label: 'Orders',
                        data: ordersTrendCounts,
                        borderColor: '#4a5bb9',
                        backgroundColor: grad,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#4a5bb9',
                        borderWidth: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tickColor, maxTicksLimit: 7 } },
                        y: { grid: { color: gridColor }, ticks: { color: tickColor, precision: 0 } },
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

