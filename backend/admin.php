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
            $totalUsers = $pdo->prepare("SELECT COUNT(*) FROM users");
            $totalUsers->execute();
            $totalUsers = (int)$totalUsers->fetchColumn();

            $totalClients = $pdo->prepare("SELECT COUNT(*) FROM clients");
            $totalClients->execute();
            $totalClients = (int)$totalClients->fetchColumn();

            $totalInvoices = $pdo->prepare("SELECT COUNT(*) FROM invoices");
            $totalInvoices->execute();
            $totalInvoices = (int)$totalInvoices->fetchColumn();

            $totalRevenue = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status = 'payée'");
            $totalRevenue->execute();
            $totalRevenue = (float)$totalRevenue->fetchColumn();

            $totalExpenses = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses");
            $totalExpenses->execute();
            $totalExpenses = (float)$totalExpenses->fetchColumn();

            $totalSubscriptions = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
            $totalSubscriptions->execute();
            $totalSubscriptions = (int)$totalSubscriptions->fetchColumn();

            $activeUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
            $activeUsers->execute();
            $activeUsers = (int)$activeUsers->fetchColumn();

            echo json_encode([
                'success' => true,
                'overview' => [
                    'total_users' => $totalUsers,
                    'total_clients' => $totalClients,
                    'total_invoices' => $totalInvoices,
                    'total_revenue' => $totalRevenue,
                    'total_expenses' => $totalExpenses,
                    'total_subscriptions' => $totalSubscriptions,
                    'active_users' => $activeUsers,
                ],
            ]);
            break;

        case 'monthly_revenue':
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status = 'payée' AND DATE_FORMAT(created_at, '%Y-%m') = :month");
                $stmt->execute([':month' => $month]);
                $months[] = ['month' => $month, 'revenue' => (float)$stmt->fetchColumn()];
            }
            echo json_encode(['success' => true, 'monthly_revenue' => $months]);
            break;

        case 'user_growth':
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = :month");
                $stmt->execute([':month' => $month]);
                $months[] = ['month' => $month, 'users' => (int)$stmt->fetchColumn()];
            }
            echo json_encode(['success' => true, 'user_growth' => $months]);
            break;

        case 'subscription_stats':
            $stmt = $pdo->prepare("SELECT plan_name, COUNT(*) as count FROM subscriptions GROUP BY plan_name ORDER BY count DESC");
            $stmt->execute();
            echo json_encode(['success' => true, 'subscription_stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}