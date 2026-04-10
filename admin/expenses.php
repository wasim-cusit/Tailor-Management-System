<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
    } else {
        $stmt = db()->prepare('INSERT INTO expenses (category, amount, expense_date, notes) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            trim($_POST['category'] ?? ''),
            (float)($_POST['amount'] ?? 0),
            $_POST['expense_date'] ?? date('Y-m-d'),
            trim($_POST['notes'] ?? '')
        ]);
        flash('success', 'Expense added.');
    }
    header('Location: ' . BASE_URL . '/admin/expenses.php');
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

$expensesSql = 'SELECT * FROM expenses';
$params = [];
if ($q !== '') {
    $expensesSql .= ' WHERE category LIKE ? OR notes LIKE ? OR expense_date LIKE ?';
    $like = "%{$q}%";
    $params = [$like, $like, $like];
}
$expensesSql .= ' ORDER BY expense_date DESC, id DESC LIMIT 100';
$expensesStmt = db()->prepare($expensesSql);
$expensesStmt->execute($params);
$expenses = $expensesStmt->fetchAll();

$totalsSql = 'SELECT COALESCE(SUM(amount), 0) FROM expenses';
if ($q !== '') {
    $totalsSql .= ' WHERE category LIKE ? OR notes LIKE ? OR expense_date LIKE ?';
}
$totalsStmt = db()->prepare($totalsSql);
$totalsStmt->execute($params);
$filteredTotal = (float)$totalsStmt->fetchColumn();

$overallTotal = (float)db()->query('SELECT COALESCE(SUM(amount), 0) FROM expenses')->fetchColumn();

$pageTitle = 'Expenses';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="row g-3 expenses-summary-row">
    <div class="col-12">
        <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
    </div>

    <div class="col-lg-4 d-flex">
        <div class="card shadow-sm expenses-summary-card expenses-summary-card--overall w-100">
            <div class="card-body">
                <div class="small text-uppercase fw-semibold mb-1">Total Expenses (All Time)</div>
                <div class="h4 mb-0 fw-bold">Rs <?= number_format($overallTotal, 0) ?></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex">
        <div class="card shadow-sm expenses-summary-card expenses-summary-card--view w-100">
            <div class="card-body">
                <div class="small text-uppercase fw-semibold mb-1">Total (Current View)</div>
                <div class="h4 mb-0 fw-bold">Rs <?= number_format($filteredTotal, 0) ?></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 d-flex">
        <div class="card shadow-sm expenses-summary-card expenses-summary-card--action w-100">
            <div class="card-body d-flex flex-column justify-content-center h-100">
                <button class="btn btn-primary w-100" type="button" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                    <i class="bi bi-plus-circle me-1"></i>Add Expense
                </button>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
                    <h2 class="h5 mb-0">Recent Expenses</h2>
                    <form method="get" class="d-flex gap-2">
                        <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search category, notes, date...">
                        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                        <?php if ($q !== ''): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/expenses.php"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead><tr><th>S.No</th><th>Date</th><th>Category</th><th class="text-end">Amount</th><th>Notes</th></tr></thead>
                        <tbody>
                        <?php $i = 1; foreach ($expenses as $eRow): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= e($eRow['expense_date']) ?></td>
                                <td><?= e($eRow['category']) ?></td>
                                <td class="text-end">Rs <?= number_format((float)$eRow['amount'], 0) ?></td>
                                <td><?= e($eRow['notes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$expenses): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No expenses found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" class="text-end">View Total</th>
                                <th class="text-end">Rs <?= number_format($filteredTotal, 0) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade create-order-modal" id="addExpenseModal" tabindex="-1" aria-labelledby="addExpenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="addExpenseModalLabel">Add Expense</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <div class="col-md-6">
                        <label class="form-label">Category</label>
                        <input class="form-control" name="category" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Expense Date</label>
                        <input type="date" class="form-control" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes"></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1"></i>Save Expense
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

