<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, name, email, created_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

$revenueStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(total), 0) AS revenue FROM invoices WHERE user_id = ? AND LOWER(status) = ? AND MONTH(due_date) = MONTH(CURDATE()) AND YEAR(due_date) = YEAR(CURDATE())'
);
$revenueStmt->execute([$userId, 'payee']);
$monthlyRevenue = (float) $revenueStmt->fetchColumn();

$invoiceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE user_id = ?');
$invoiceCountStmt->execute([$userId]);
$invoiceCount = (int) $invoiceCountStmt->fetchColumn();

$newClientsStmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)');
$newClientsStmt->execute([$userId]);
$newClients = (int) $newClientsStmt->fetchColumn();

$ticketsStmt = $pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?');
$ticketsStmt->execute([$userId]);
$totalTickets = (int) $ticketsStmt->fetchColumn();

$closedTicketsStmt = $pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status != ?');
$closedTicketsStmt->execute([$userId, 'open']);
$closedTickets = (int) $closedTicketsStmt->fetchColumn();

$responseRate = $totalTickets > 0 ? round(($closedTickets / $totalTickets) * 100) : 100;

// Variations réelles (mois précédent / période précédente)
$prevRevenueStmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = ? AND LOWER(status) = ? AND YEAR(due_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(due_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))');
$prevRevenueStmt->execute([$userId, 'payee']);
$prevMonthRevenue = (float) $prevRevenueStmt->fetchColumn();

$prevInvoiceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE user_id = ? AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))');
$prevInvoiceCountStmt->execute([$userId]);
$prevMonthInvoices = (int) $prevInvoiceCountStmt->fetchColumn();

$monthInvoiceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE user_id = ? AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())');
$monthInvoiceCountStmt->execute([$userId]);
$monthInvoices = (int) $monthInvoiceCountStmt->fetchColumn();

$prevClientsStmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)');
$prevClientsStmt->execute([$userId]);
$prev30Clients = (int) $prevClientsStmt->fetchColumn();

$months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $months[] = $m;
}
$monthLabels = [];
$monthRevenue = [];
foreach ($months as $m) {
    $monthLabels[] = date('M Y', strtotime($m . '-01'));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = ? AND LOWER(status) = ? AND due_date LIKE ?');
    $stmt->execute([$userId, 'payee', $m . '-%']);
    $monthRevenue[] = (float) $stmt->fetchColumn();
}

$statusCounts = ['brouillon' => 0, 'en_attente' => 0, 'payee' => 0, 'en_retard' => 0, 'annulee' => 0];
$stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM invoices WHERE user_id = ? GROUP BY status');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['n'];
}

echo json_encode([
    'user' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'created_at' => $user['created_at'],
    ],
    'stats' => [
        'monthly_revenue' => $monthlyRevenue,
        'invoice_count' => $invoiceCount,
        'new_clients' => $newClients,
        'ticket_response_rate' => $responseRate,
        'revenue_prev_month' => $prevMonthRevenue,
        'invoice_count_month' => $monthInvoices,
        'invoice_count_prev_month' => $prevMonthInvoices,
        'new_clients_prev' => $prev30Clients,
    ],
    'charts' => [
        'month_labels' => $monthLabels,
        'month_revenue' => $monthRevenue,
        'status_counts' => $statusCounts,
    ],
]);
