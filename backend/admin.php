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

        case 'stats':
            $q = function (string $sql) use ($pdo) {
                return (int) $pdo->query($sql)->fetchColumn();
            };
            $qFloat = function (string $sql) use ($pdo) {
                return (float) $pdo->query($sql)->fetchColumn();
            };

            // ---- Compteurs par module ----
            $counts = [
                'users'            => $q('SELECT COUNT(*) FROM users'),
                'clients'          => $q('SELECT COUNT(*) FROM clients'),
                'products'         => $q('SELECT COUNT(*) FROM products'),
                'suppliers'        => $q('SELECT COUNT(*) FROM suppliers'),
                'stock'            => $q('SELECT COUNT(*) FROM stock'),
                'stock_units'      => $q('SELECT COALESCE(SUM(quantity), 0) FROM stock'),
                'low_stock'        => $q('SELECT COUNT(*) FROM stock WHERE quantity <= reorder_level AND reorder_level > 0'),
                'invoices'         => $q('SELECT COUNT(*) FROM invoices'),
                'quotes'           => $q('SELECT COUNT(*) FROM quotes'),
                'payments'         => $q('SELECT COUNT(*) FROM payments'),
                'expenses'         => $q('SELECT COUNT(*) FROM expenses'),
                'subscriptions'    => $q('SELECT COUNT(*) FROM subscriptions'),
                'tickets'          => $q('SELECT COUNT(*) FROM support_tickets'),
                'tickets_open'     => $q("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('closed', 'resolu')"),
                'notifications'    => $q('SELECT COUNT(*) FROM notifications'),
                'referrals'        => $q('SELECT COUNT(*) FROM referrals'),
            ];

            // ---- Répartitions ----
            $repart = function (string $sql) use ($pdo) {
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                $out = [];
                foreach ($rows as $r) {
                    $out[] = ['label' => $r['label'], 'value' => (int) $r['value']];
                }
                return $out;
            };

            $invoiceStatus = $repart("SELECT CASE WHEN status IN ('payee','payée') THEN 'Payée' WHEN status IN ('en_attente','en attente') THEN 'En attente' WHEN status IN ('en_retard','en retard') THEN 'En retard' WHEN status = 'brouillon' THEN 'Brouillon' WHEN status IN ('annulee','annulée') THEN 'Annulée' ELSE status END AS label, COUNT(*) AS value FROM invoices GROUP BY label ORDER BY value DESC");
            $quoteStatus = $repart("SELECT CASE WHEN status IN ('accepte','accepté') THEN 'Accepté' WHEN status IN ('envoye','envoyé') THEN 'Envoyé' WHEN status = 'brouillon' THEN 'Brouillon' WHEN status IN ('refuse','refusé') THEN 'Refusé' WHEN status = 'expire' THEN 'Expiré' ELSE status END AS label, COUNT(*) AS value FROM quotes GROUP BY label ORDER BY value DESC");
            $planStats = $repart("SELECT COALESCE(NULLIF(plan_name, ''), 'Aucun') AS label, COUNT(*) AS value FROM subscriptions GROUP BY label ORDER BY value DESC");
            $expenseByCat = [];
            $expStmt = $pdo->query("SELECT COALESCE(NULLIF(category, ''), 'Autres') AS label, SUM(amount) AS value FROM expenses GROUP BY label ORDER BY value DESC LIMIT 8");
            foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $expenseByCat[] = ['label' => $r['label'], 'value' => round((float) $r['value'], 2)];
            }
            $paymentMethod = $repart("SELECT COALESCE(NULLIF(payment_method, ''), 'Autre') AS label, COUNT(*) AS value FROM payments GROUP BY label ORDER BY value DESC");

            // ---- Montants ----
            $amounts = [
                'revenue_total'      => $qFloat("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status IN ('payee', 'payée')"),
                'revenue_pending'    => $qFloat("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status IN ('en_attente', 'en_retard') AND status NOT IN ('payee', 'payée')"),
                'revenue_this_month' => $qFloat("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status IN ('payee', 'payée') AND DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')"),
                'expenses_total'     => $qFloat('SELECT COALESCE(SUM(amount), 0) FROM expenses'),
                'payments_total'     => $qFloat("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'"),
                'mrr_total'          => $qFloat("SELECT COALESCE(SUM(price), 0) FROM subscriptions WHERE status = 'active'"),
                'stock_value'        => $qFloat('SELECT COALESCE(SUM(s.quantity * p.unit_price), 0) FROM stock s LEFT JOIN products p ON p.id = s.product_id'),
            ];

            // ---- Top clients / produits / dépenses par mois ----
            $topClients = [];
            $tcStmt = $pdo->query("SELECT c.name AS label, SUM(i.total) AS value FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('payee', 'payée') GROUP BY c.id, c.name ORDER BY value DESC LIMIT 5");
            foreach ($tcStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $topClients[] = ['label' => $r['label'], 'value' => round((float) $r['value'], 2)];
            }
            $topProducts = [];
            $tpStmt = $pdo->query("SELECT p.name AS label, SUM(ii.quantity) AS value FROM invoice_items ii JOIN products p ON p.id = ii.product_id JOIN invoices i ON i.id = ii.invoice_id AND i.status NOT IN ('brouillon', 'annulee', 'annulée') GROUP BY p.id, p.name ORDER BY value DESC LIMIT 5");
            foreach ($tpStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $topProducts[] = ['label' => $r['label'], 'value' => (int) $r['value']];
            }
            $lowStockItems = [];
            $lsStmt = $pdo->query("SELECT p.name AS label, s.quantity AS value, s.reorder_level FROM stock s JOIN products p ON p.id = s.product_id WHERE s.quantity <= s.reorder_level AND s.reorder_level > 0 ORDER BY s.quantity ASC LIMIT 8");
            foreach ($lsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lowStockItems[] = ['label' => $r['label'], 'value' => (int) $r['value'], 'reorder' => (int) $r['reorder_level']];
            }

            // ---- Série 12 mois (revenus + utilisateurs) ----
            $series = [];
            for ($i = 11; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $revStmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status IN ('payee', 'payée') AND DATE_FORMAT(paid_at, '%Y-%m') = :m");
                $revStmt->execute([':m' => $month]);
                $usrStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = :m");
                $usrStmt->execute([':m' => $month]);
                $series[] = ['month' => $month, 'revenue' => (float) $revStmt->fetchColumn(), 'users' => (int) $usrStmt->fetchColumn()];
            }

            echo json_encode([
                'success' => true,
                'stats' => [
                    'counts'     => $counts,
                    'amounts'    => $amounts,
                    'series'     => $series,
                    'invoice_status' => $invoiceStatus,
                    'quote_status'   => $quoteStatus,
                    'plans'          => $planStats,
                    'expenses_by_cat'=> $expenseByCat,
                    'payment_method' => $paymentMethod,
                    'top_clients'    => $topClients,
                    'top_products'   => $topProducts,
                    'low_stock'      => $lowStockItems,
                ],
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