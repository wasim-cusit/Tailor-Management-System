<?php
require_once __DIR__ . '/../config/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT c.*, COUNT(o.id) AS total_orders
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE c.full_name LIKE ? OR c.phone LIKE ? OR c.customer_code LIKE ? OR c.address LIKE ? OR c.notes LIKE ?';
    $params = ["%$q%", "%$q%", "%$q%", "%$q%", "%$q%"];
}
$sql .= ' GROUP BY c.id ORDER BY c.id DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$rowsHtml = '';
$i = 1;
foreach ($customers as $c) {
    $id = (int)$c['id'];
    $fullName = e($c['full_name']);
    $payload = e(json_encode([
        'id' => $id,
        'full_name' => (string)($c['full_name'] ?? ''),
        'phone' => (string)($c['phone'] ?? ''),
        'address' => (string)($c['address'] ?? ''),
        'notes' => (string)($c['notes'] ?? ''),
    ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT));
    $rowsHtml .= '<tr>';
    $rowsHtml .= '<td>' . $i++ . '</td>';
    $rowsHtml .= '<td>' . e($c['customer_code']) . '</td>';
    $rowsHtml .= '<td><a href="' . BASE_URL . '/admin/customer_profile.php?id=' . $id . '" class="text-decoration-none fw-semibold">' . $fullName . '</a></td>';
    $rowsHtml .= '<td>' . e($c['phone']) . '</td>';
    $rowsHtml .= '<td class="text-center">' . (int)$c['total_orders'] . '</td>';
    $rowsHtml .= '<td class="text-center">';
    $rowsHtml .= '<div class="action-icons" role="group" aria-label="Customer actions">';
    $rowsHtml .= '<a class="action-icon action-view" href="' . BASE_URL . '/admin/customer_profile.php?id=' . $id . '" aria-label="View customer profile" title="View profile"><i class="bi bi-eye"></i></a>';
    $rowsHtml .= '<button type="button" class="action-icon action-edit customer-edit-btn" data-bs-toggle="modal" data-bs-target="#editCustomerModal" data-customer=\'' . $payload . '\' aria-label="Edit customer" title="Edit"><i class="bi bi-pencil-square"></i></button>';
    $rowsHtml .= '<form method="post" class="d-inline js-confirm-delete" data-confirm-name="' . $fullName . '">';
    $rowsHtml .= '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
    $rowsHtml .= '<input type="hidden" name="action" value="delete">';
    $rowsHtml .= '<input type="hidden" name="customer_id" value="' . $id . '">';
    $rowsHtml .= '<input type="hidden" name="q_back" value="' . e($q) . '">';
    $rowsHtml .= '<button class="action-icon action-delete" type="submit" aria-label="Delete customer" title="Delete"><i class="bi bi-trash"></i></button>';
    $rowsHtml .= '</form>';
    $rowsHtml .= '</div>';
    $rowsHtml .= '</td>';
    $rowsHtml .= '</tr>';
}

if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="6" class="text-center text-muted py-4">No customers found.</td></tr>';
}

echo json_encode([
    'ok' => true,
    'rowsHtml' => $rowsHtml,
    'count' => count($customers),
], JSON_UNESCAPED_SLASHES);

