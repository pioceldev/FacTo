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
$action = $_GET['action'] ?? 'overview';

try {
    switch ($action) {
        case 'overview':
            $totalInvoices = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = :uid");
            $totalInvoices->execute([':uid' => $userId]);
            $totalInvoices = (int)$totalInvoices->fetchColumn();

            $paidInvoices = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = :uid AND status = 'payée'");
            $paidInvoices->execute([':uid' => $userId]);
            $paidInvoices = (int)$paidInvoices->fetchColumn();

            $totalRevenue = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = :uid AND status = 'payée'");
            $totalRevenue->execute([':uid' => $userId]);
            $totalRevenue = (float)$totalRevenue->fetchColumn();

            $totalExpenses = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = :uid");
            $totalExpenses->execute([':uid' => $userId]);
            $totalExpenses = (float)$totalExpenses->fetchColumn();

            $totalPayments = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = :uid");
            $totalPayments->execute([':uid' => $userId]);
            $totalPayments = (float)$totalPayments->fetchColumn();

            $totalQuotes = $pdo->prepare("SELECT COUNT(*) FROM quotes WHERE user_id = :uid");
            $totalQuotes->execute([':uid' => $userId]);
            $totalQuotes = (int)$totalQuotes->fetchColumn();

            $totalClients = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE user_id = :uid");
            $totalClients->execute([':uid' => $userId]);
            $totalClients = (int)$totalClients->fetchColumn();

            $totalProducts = $pdo->prepare("SELECT COUNT(*) FROM products WHERE user_id = :uid");
            $totalProducts->execute([':uid' => $userId]);
            $totalProducts = (int)$totalProducts->fetchColumn();

            echo json_encode([
                'success' => true,
                'overview' => [
                    'total_invoices' => $totalInvoices,
                    'paid_invoices' => $paidInvoices,
                    'total_revenue' => $totalRevenue,
                    'total_expenses' => $totalExpenses,
                    'total_payments' => $totalPayments,
                    'total_quotes' => $totalQuotes,
                    'total_clients' => $totalClients,
                    'total_products' => $totalProducts,
                ],
            ]);
            break;

        case 'monthly_revenue':
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = :uid AND status = 'payée' AND DATE_FORMAT(created_at, '%Y-%m') = :month");
                $stmt->execute([':uid' => $userId, ':month' => $month]);
                $months[] = ['month' => $month, 'revenue' => (float)$stmt->fetchColumn()];
            }
            echo json_encode(['success' => true, 'monthly_revenue' => $months]);
            break;

        case 'invoice_status':
            $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM invoices WHERE user_id = :uid GROUP BY status");
            $stmt->execute([':uid' => $userId]);
            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'invoice_status' => $statuses]);
            break;

        case 'monthly_expenses':
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = :uid AND DATE_FORMAT(expense_date, '%Y-%m') = :month");
                $stmt->execute([':uid' => $userId, ':month' => $month]);
                $months[] = ['month' => $month, 'expenses' => (float)$stmt->fetchColumn()];
            }
            echo json_encode(['success' => true, 'monthly_expenses' => $months]);
            break;

        case 'top_clients':
            $stmt = $pdo->prepare("
                SELECT c.name, c.email, COALESCE(SUM(i.total), 0) as total_billed, COUNT(i.id) as invoice_count
                FROM clients c LEFT JOIN invoices i ON c.id = i.client_id AND i.status = 'payée'
                WHERE c.user_id = :uid GROUP BY c.id ORDER BY total_billed DESC LIMIT 5
            ");
            $stmt->execute([':uid' => $userId]);
            echo json_encode(['success' => true, 'top_clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'expense_by_category':
            $stmt = $pdo->prepare("SELECT category, COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = :uid GROUP BY category ORDER BY total DESC");
            $stmt->execute([':uid' => $userId]);
            echo json_encode(['success' => true, 'expense_by_category' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'payment_methods':
            $stmt = $pdo->prepare("SELECT payment_method, COALESCE(SUM(amount), 0) as total FROM payments WHERE user_id = :uid GROUP BY payment_method ORDER BY total DESC");
            $stmt->execute([':uid' => $userId]);
            echo json_encode(['success' => true, 'payment_methods' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}