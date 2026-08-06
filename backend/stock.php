<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();

function readBody()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

$action = $_GET['action'] ?? 'list';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorise']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            $page = max(1, intval($_GET['page'] ?? 1));
            $perPage = max(1, intval($_GET['per_page'] ?? 15));
            $offset = ($page - 1) * $perPage;
            $q = trim($_GET['q'] ?? '');

            $where = 'WHERE s.user_id = :uid';
            $params = [':uid' => $userId];
            if ($q !== '') {
                $where .= ' AND (p.name LIKE :q OR p.sku LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock s JOIN products p ON s.product_id = p.id $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            $pages = max(1, (int)ceil($total / $perPage));

            $stmt = $pdo->prepare("
                SELECT s.id, s.product_id, s.quantity, s.reorder_level, s.updated_at,
                       p.name AS product_name, p.sku, p.sku AS reference, p.unit_price, p.is_active
                FROM stock s JOIN products p ON s.product_id = p.id
                $where ORDER BY s.updated_at DESC LIMIT :limit OFFSET :offset
            ");
            $params[':limit'] = $perPage;
            $params[':offset'] = $offset;
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Statistiques globales (indépendantes de la pagination)
            $statsParams = $params;
            unset($statsParams[':limit'], $statsParams[':offset']);
            $statsStmt = $pdo->prepare("SELECT COUNT(*) AS total_products,
                COALESCE(SUM(CASE WHEN s.quantity <= s.reorder_level AND s.reorder_level > 0 THEN 1 ELSE 0 END), 0) AS low_stock,
                COALESCE(SUM(s.quantity * p.unit_price), 0) AS total_value
                FROM stock s JOIN products p ON s.product_id = p.id $where");
            $statsStmt->execute($statsParams);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'stock' => $items,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages],
                'stats' => $stats,
            ]);
            break;

        case 'products':
            $stmt = $pdo->prepare("SELECT id, name, sku, unit_price, sku AS reference FROM products WHERE user_id = :uid AND is_active = 1 ORDER BY name");
            $stmt->execute([':uid' => $userId]);
            echo json_encode(['success' => true, 'products' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get':
            $id = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT s.*, p.name AS product_name, p.sku, p.unit_price, p.is_active
                FROM stock s JOIN products p ON s.product_id = p.id
                WHERE s.id = :id AND s.user_id = :uid
            ");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) { http_response_code(404); echo json_encode(['error' => 'Introuvable']); exit; }
            echo json_encode(['success' => true, 'stock' => $item]);
            break;

        case 'create':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!csrfValidateToken($input['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
            $productId = intval($input['product_id'] ?? 0);
            $quantity = intval($input['quantity'] ?? 0);
            $reorderLevel = intval($input['reorder_level'] ?? 0);

            if ($productId <= 0) { http_response_code(400); echo json_encode(['error' => 'product_id requis']); exit; }
            if ($quantity < 0 || $reorderLevel < 0) { http_response_code(400); echo json_encode(['error' => 'Quantité ou seuil négatif invalide']); exit; }

            $check = $pdo->prepare("SELECT id FROM products WHERE id = :pid AND user_id = :uid");
            $check->execute([':pid' => $productId, ':uid' => $userId]);
            if (!$check->fetch()) { http_response_code(400); echo json_encode(['error' => 'Produit introuvable']); exit; }

            $exists = $pdo->prepare("SELECT id FROM stock WHERE product_id = :pid AND user_id = :uid");
            $exists->execute([':pid' => $productId, ':uid' => $userId]);
            if ($exists->fetch()) { http_response_code(409); echo json_encode(['error' => 'Deja en stock pour ce produit']); exit; }

            $stmt = $pdo->prepare("INSERT INTO stock (user_id, product_id, quantity, reorder_level) VALUES (:uid, :pid, :qty, :rl)");
            $stmt->execute([':uid' => $userId, ':pid' => $productId, ':qty' => $quantity, ':rl' => $reorderLevel]);
            $newId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT s.*, p.name AS product_name, p.sku FROM stock s JOIN products p ON s.product_id = p.id WHERE s.id = :id");
            $stmt->execute([':id' => $newId]);
            echo json_encode(['success' => true, 'message' => 'Stock enregistre', 'stock' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'update':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!csrfValidateToken($input['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
            $id = intval($input['id'] ?? 0);
            $quantity = intval($input['quantity'] ?? 0);
            $reorderLevel = intval($input['reorder_level'] ?? 0);

            if ($quantity < 0 || $reorderLevel < 0) { http_response_code(400); echo json_encode(['error' => 'Quantité ou seuil négatif invalide']); exit; }

            $stmt = $pdo->prepare("UPDATE stock SET quantity = :qty, reorder_level = :rl, updated_at = NOW() WHERE id = :id AND user_id = :uid");
            $stmt->execute([':qty' => $quantity, ':rl' => $reorderLevel, ':id' => $id, ':uid' => $userId]);
            if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['error' => 'Introuvable']); exit; }

            $stmt = $pdo->prepare("SELECT s.*, p.name AS product_name, p.sku FROM stock s JOIN products p ON s.product_id = p.id WHERE s.id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Stock mis a jour', 'stock' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'delete':
            $body = readBody();
            if (!csrfValidateToken($body['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
            $id = intval($body['id'] ?? $_GET['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM stock WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['error' => 'Introuvable']); exit; }
            echo json_encode(['success' => true, 'message' => 'Stock supprime']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}