<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Sécurité : l'administration (toutes les actions) est réservée aux admins.
if (($_SESSION['user_role'] ?? 'user') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Accès réservé aux administrateurs']);
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

            // Les statuts sont stockés sans accents ('payee'), on couvre les deux cas
            $totalRevenue = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status IN ('payee', 'payée')");
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

            $revenueThisMonth = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status IN ('payee', 'payée') AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')");
            $revenueThisMonth->execute();
            $revenueThisMonth = (float)$revenueThisMonth->fetchColumn();

            $mrrTotal = $pdo->prepare("SELECT COALESCE(SUM(price), 0) FROM subscriptions WHERE status = 'active'");
            $mrrTotal->execute();
            $mrrTotal = (float)$mrrTotal->fetchColumn();

            echo json_encode([
                'success' => true,
                'overview' => [
                    'total_users' => $totalUsers,
                    'total_clients' => $totalClients,
                    'total_invoices' => $totalInvoices,
                    'total_revenue' => $totalRevenue,
                    'revenue_this_month' => $revenueThisMonth,
                    'total_expenses' => $totalExpenses,
                    'total_subscriptions' => $totalSubscriptions,
                    'mrr_total' => $mrrTotal,
                    'active_users' => $activeUsers,
                ],
            ]);
            break;

        case 'monthly_revenue':
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status IN ('payee', 'payée') AND DATE_FORMAT(created_at, '%Y-%m') = :month");
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

        case 'companies':
            $q = trim($_GET['q'] ?? '');
            $where = 'WHERE 1=1';
            $params = [];
            if ($q !== '') {
                $where .= ' AND (u.name LIKE :q OR u.company LIKE :q OR u.email LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
            $countStmt->execute($params);
            $totalCompanies = (int) $countStmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.company, u.role, u.created_at,
                       s.plan_name, s.status AS sub_status, s.price,
                       (SELECT COUNT(*) FROM invoices i WHERE i.user_id = u.id) AS invoices_count
                FROM users u
                LEFT JOIN (
                    SELECT user_id, plan_name, status, price,
                           ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY id DESC) AS rn
                    FROM subscriptions
                ) s ON s.user_id = u.id AND s.rn = 1
                $where
                ORDER BY u.created_at DESC
                LIMIT 100
            ");
            $stmt->execute($params);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'companies' => $companies,
                'total' => $totalCompanies,
            ]);
            break;

        case 'activity_logs':
            $limit = max(1, min(200, (int)($_GET['limit'] ?? 20)));
            $stmt = $pdo->prepare("
                SELECT al.id, al.level, al.message, al.created_at, u.name AS user_name
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                ORDER BY al.created_at DESC
                LIMIT $limit
            ");
            $stmt->execute();
            echo json_encode(['success' => true, 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}