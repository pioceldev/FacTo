<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';
require __DIR__ . '/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$userId = $_SESSION['user_id'];

function readBody()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $page = max(1, intval($_GET['page'] ?? 1));
            $perPage = max(1, intval($_GET['per_page'] ?? 15));
            $offset = ($page - 1) * $perPage;
            $q = trim($_GET['q'] ?? '');

            $where = 'WHERE user_id = :uid';
            $params = [':uid' => $userId];
            if ($q !== '') {
                $where .= ' AND (description LIKE :q OR category LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            $pages = max(1, (int)ceil($total / $perPage));

            $stmt = $pdo->prepare("SELECT * FROM expenses $where ORDER BY expense_date DESC, created_at DESC LIMIT :limit OFFSET :offset");
            $params[':limit'] = $perPage;
            $params[':offset'] = $offset;
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'expenses' => $items,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages],
            ]);
            break;

        case 'get':
            $id = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$expense) { http_response_code(404); echo json_encode(['error' => 'Introuvable']); exit; }
            echo json_encode(['success' => true, 'expense' => $expense]);
            break;

        case 'create':
            $body = readBody();
            $category = trim($body['category'] ?? '');
            $description = trim($body['description'] ?? '');
            $amount = floatval($body['amount'] ?? 0);
            $expenseDate = $body['expense_date'] ?? date('Y-m-d');
            $receiptPath = trim($body['receipt_path'] ?? '');

            if ($amount <= 0) { http_response_code(400); echo json_encode(['error' => 'Montant invalide']); exit; }

            $stmt = $pdo->prepare("INSERT INTO expenses (user_id, category, description, amount, expense_date, receipt_path) VALUES (:uid, :cat, :desc, :amount, :edate, :receipt)");
            $stmt->execute([
                ':uid' => $userId, ':cat' => $category ?: null,
                ':desc' => $description ?: null, ':amount' => $amount,
                ':edate' => $expenseDate, ':receipt' => $receiptPath ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = :id");
            $stmt->execute([':id' => $newId]);
            echo json_encode(['success' => true, 'message' => 'Dépense enregistrée', 'expense' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'update':
            $body = readBody();
            $id = intval($body['id'] ?? 0);
            $category = trim($body['category'] ?? '');
            $description = trim($body['description'] ?? '');
            $amount = floatval($body['amount'] ?? 0);
            $expenseDate = $body['expense_date'] ?? null;
            $receiptPath = trim($body['receipt_path'] ?? '');

            $check = $pdo->prepare("SELECT id FROM expenses WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Dépense introuvable']); exit; }

            $pdo->prepare("UPDATE expenses SET category = :cat, description = :desc, amount = :amount, expense_date = :edate, receipt_path = :receipt, updated_at = NOW() WHERE id = :id AND user_id = :uid")
                ->execute([
                    ':cat' => $category ?: null, ':desc' => $description ?: null,
                    ':amount' => $amount, ':edate' => $expenseDate,
                    ':receipt' => $receiptPath ?: null, ':id' => $id, ':uid' => $userId,
                ]);

            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Dépense mise a jour', 'expense' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'delete':
            $body = readBody();
            $id = intval($body['id'] ?? 0);

            $check = $pdo->prepare("SELECT id FROM expenses WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Dépense introuvable']); exit; }

            $pdo->prepare("DELETE FROM expenses WHERE id = :id AND user_id = :uid")->execute([':id' => $id, ':uid' => $userId]);

            echo json_encode(['success' => true, 'message' => 'Dépense supprimée']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}