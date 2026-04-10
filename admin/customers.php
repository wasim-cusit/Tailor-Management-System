<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();

function redirectCustomers(string $q = ''): void
{
    $url = BASE_URL . '/admin/customers.php';
    if ($q !== '') {
        $url .= '?q=' . urlencode($q);
    }
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        flash('error', 'Invalid CSRF token.');
        redirectCustomers();
    }

    $action = (string)($_POST['action'] ?? 'create');
    $qBack = trim((string)($_POST['q_back'] ?? ''));

    if ($action === 'delete') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        if ($customerId <= 0) {
            flash('error', 'Invalid customer.');
            redirectCustomers($qBack);
        }

        $cnt = db()->prepare('SELECT COUNT(*) FROM orders WHERE customer_id = ?');
        $cnt->execute([$customerId]);
        $hasOrders = (int)$cnt->fetchColumn();
        if ($hasOrders > 0) {
            flash('error', 'Cannot delete customer with existing orders.');
            redirectCustomers($qBack);
        }

        db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
        flash('success', 'Customer deleted.');
        redirectCustomers($qBack);
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($fullName !== '' && $phone !== '') {
        if ($action === 'update') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            if ($customerId <= 0) {
                flash('error', 'Invalid customer.');
                redirectCustomers($qBack);
            }

            $stmt = db()->prepare('UPDATE customers SET full_name = ?, phone = ?, address = ?, notes = ? WHERE id = ?');
            $stmt->execute([$fullName, $phone, $address, $notes, $customerId]);
            flash('success', 'Customer updated.');
        } else {
            $stmt = db()->prepare('INSERT INTO customers (customer_code, full_name, phone, address, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([generateCode('CUS'), $fullName, $phone, $address, $notes]);
            flash('success', 'Customer added.');
        }
    } else {
        flash('error', 'Name and phone are required.');
    }
    redirectCustomers($qBack);
}

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT c.*, COUNT(o.id) AS total_orders FROM customers c LEFT JOIN orders o ON o.customer_id = c.id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE c.full_name LIKE ? OR c.phone LIKE ? OR c.customer_code LIKE ? OR c.address LIKE ? OR c.notes LIKE ?';
    $params = ["%$q%", "%$q%", "%$q%", "%$q%", "%$q%"];
}
$sql .= ' GROUP BY c.id ORDER BY c.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers';
$pageLayout = 'admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_layout_start.php';
?>
<div class="row g-3">
    <div class="col-12">
        <?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    </div>
    <div class="col-lg-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <h2 class="h5 mb-0">Customer List</h2>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                            <i class="bi bi-plus-lg me-1"></i>Add Customer
                        </button>
                        <form id="customerSearchForm" method="get" class="d-flex gap-2">
                            <div class="position-relative">
                                <input id="customerSearchInput" class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search name, phone, code..." autocomplete="off">
                                <span id="customerSearchSpinner" class="search-spinner d-none"></span>
                            </div>
                            <button id="customerSearchClear" class="btn btn-sm btn-outline-secondary" type="button" title="Clear search" aria-label="Clear search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead><tr><th>S.No</th><th>Code</th><th>Name</th><th>Phone</th><th class="text-center">Orders</th><th class="text-center">Actions</th></tr></thead>
                        <tbody id="customersTbody">
                        <?php $i = 1; foreach ($customers as $c): ?>
                            <?php
                            $customerPayload = [
                                'id' => (int)$c['id'],
                                'full_name' => (string)($c['full_name'] ?? ''),
                                'phone' => (string)($c['phone'] ?? ''),
                                'address' => (string)($c['address'] ?? ''),
                                'notes' => (string)($c['notes'] ?? ''),
                            ];
                            ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= e($c['customer_code']) ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>/admin/customer_profile.php?id=<?= (int)$c['id'] ?>" class="text-decoration-none fw-semibold">
                                        <?= e($c['full_name']) ?>
                                    </a>
                                </td>
                                <td><?= e($c['phone']) ?></td>
                                <td class="text-center"><?= (int)$c['total_orders'] ?></td>
                                <td class="text-center">
                                    <div class="action-icons" role="group" aria-label="Customer actions">
                                        <a class="action-icon action-view" href="<?= BASE_URL ?>/admin/customer_profile.php?id=<?= (int)$c['id'] ?>" aria-label="View customer profile" title="View profile">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button
                                            type="button"
                                            class="action-icon action-edit customer-edit-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editCustomerModal"
                                            data-customer='<?= e(json_encode($customerPayload, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT)) ?>'
                                            aria-label="Edit customer"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <form method="post" class="d-inline js-confirm-delete" data-confirm-name="<?= e($c['full_name']) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="q_back" value="<?= e($q) ?>">
                                            <button class="action-icon action-delete" type="submit" aria-label="Delete customer" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade create-order-modal" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="addCustomerModalLabel">Add Customer</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="q_back" value="<?= e($q) ?>">
                    <div class="col-12">
                        <label class="form-label">Full Name</label>
                        <input class="form-control" name="full_name" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="phone" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes"></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1"></i>Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade create-order-modal" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="editCustomerModalLabel">Edit Customer</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" class="row g-2" id="editCustomerForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="customer_id" value="">
                    <input type="hidden" name="q_back" value="<?= e($q) ?>">
                    <div class="col-12">
                        <label class="form-label">Full Name</label>
                        <input class="form-control" name="full_name" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Phone</label>
                        <input class="form-control" name="phone" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes"></textarea>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1"></i>Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        const input = document.getElementById('customerSearchInput');
        const clearBtn = document.getElementById('customerSearchClear');
        const tbody = document.getElementById('customersTbody');
        const spinner = document.getElementById('customerSearchSpinner');
        if (!input || !tbody || !clearBtn) return;

        let t = null;
        let controller = null;

        function setLoading(isLoading) {
            if (!spinner) return;
            spinner.classList.toggle('d-none', !isLoading);
        }

        async function runSearch(q) {
            if (controller) controller.abort();
            controller = new AbortController();
            setLoading(true);
            try {
                const url = new URL('<?= BASE_URL ?>/admin/customers_search.php', window.location.origin);
                url.searchParams.set('q', q);
                const res = await fetch(url.toString(), { signal: controller.signal, headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data && data.ok) {
                    tbody.innerHTML = data.rowsHtml;
                }
            } catch (e) {
                // ignore abort errors
            } finally {
                setLoading(false);
            }
        }

        input.addEventListener('input', function () {
            if (t) window.clearTimeout(t);
            t = window.setTimeout(function () {
                runSearch(input.value || '');
            }, 250);
        });

        clearBtn.addEventListener('click', function () {
            input.value = '';
            runSearch('');
            input.focus();
        });

        // initial state: hide clear if empty
        if (!input.value) {
            clearBtn.classList.add('disabled');
        }
        input.addEventListener('input', function () {
            clearBtn.classList.toggle('disabled', !input.value);
        });

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.customer-edit-btn');
            if (!btn) return;
            const raw = btn.getAttribute('data-customer');
            if (!raw) return;
            let customer = null;
            try { customer = JSON.parse(raw); } catch (_) { return; }
            if (!customer) return;

            const form = document.getElementById('editCustomerForm');
            if (!form) return;
            const setVal = function (name, value) {
                const el = form.querySelector('[name="' + name + '"]');
                if (!el) return;
                el.value = value ?? '';
            };
            setVal('customer_id', customer.id);
            setVal('full_name', customer.full_name);
            setVal('phone', customer.phone);
            setVal('address', customer.address);
            setVal('notes', customer.notes);
        });
    })();
</script>
<?php
require __DIR__ . '/../includes/admin_layout_end.php';
require __DIR__ . '/../includes/footer.php';
?>

