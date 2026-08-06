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

            $where = 'WHERE q.user_id = :uid';
            $params = [':uid' => $userId];
            if ($q !== '') {
                $where .= ' AND (q.ref LIKE :q OR c.name LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM quotes q LEFT JOIN clients c ON q.client_id = c.id $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            $pages = max(1, (int)ceil($total / $perPage));

            $stmt = $pdo->prepare("
                SELECT q.*, c.name AS client_name, c.email AS client_email
                FROM quotes q LEFT JOIN clients c ON q.client_id = c.id
                $where ORDER BY q.created_at DESC LIMIT :limit OFFSET :offset
            ");
            $params[':limit'] = $perPage;
            $params[':offset'] = $offset;
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Statistiques globales (indépendantes de la pagination)
            $statsParams = $params;
            unset($statsParams[':limit'], $statsParams[':offset']);
            $statsStmt = $pdo->prepare("SELECT COUNT(*) AS count,
                COALESCE(SUM(q.total), 0) AS total,
                COALESCE(SUM(CASE WHEN LOWER(q.status) IN ('brouillon', 'envoye', 'envoyé') THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN LOWER(q.status) IN ('accepte', 'accepté') THEN 1 ELSE 0 END), 0) AS accepted
                FROM quotes q LEFT JOIN clients c ON q.client_id = c.id $where");
            $statsStmt->execute($statsParams);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'quotes' => $items,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages],
                'stats' => $stats,
            ]);
            break;

        case 'get':
            $id = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT q.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address
                FROM quotes q LEFT JOIN clients c ON q.client_id = c.id
                WHERE q.id = :id AND q.user_id = :uid
            ");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$quote) { http_response_code(404); echo json_encode(['error' => 'Introuvable']); exit; }

            $itemsStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = :qid ORDER BY id");
            $itemsStmt->execute([':qid' => $id]);
            $quote['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'quote' => $quote]);
            break;

        case 'create':
            $body = readBody();
            $clientId = intval($body['client_id'] ?? 0);
            $ref = trim($body['ref'] ?? '');
            $status = trim($body['status'] ?? 'brouillon');
            $validUntil = $body['valid_until'] ?? null;
            $items = $body['items'] ?? [];

            if ($clientId <= 0) { http_response_code(400); echo json_encode(['error' => 'client_id requis']); exit; }

            $clientCheck = $pdo->prepare("SELECT id FROM clients WHERE id = :cid AND user_id = :uid");
            $clientCheck->execute([':cid' => $clientId, ':uid' => $userId]);
            if (!$clientCheck->fetch()) { http_response_code(400); echo json_encode(['error' => 'Client introuvable']); exit; }

            if ($ref === '') {
                $ref = 'DEV-' . strtoupper(substr(uniqid(), -6));
            }

            $amount = 0;
            $vat = 0;
            foreach ($items as $item) {
                $qty = floatval($item['quantity'] ?? 0);
                $price = floatval($item['unit_price'] ?? 0);
                $lineTotal = $qty * $price;
                $amount += $lineTotal;
                $vat += $lineTotal * (floatval($item['vat_rate'] ?? 0) / 100);
            }
            $total = $amount + $vat;

            $stmt = $pdo->prepare("INSERT INTO quotes (user_id, client_id, ref, status, amount, vat, total, valid_until) VALUES (:uid, :cid, :ref, :status, :amount, :vat, :total, :valid_until)");
            $stmt->execute([
                ':uid' => $userId, ':cid' => $clientId, ':ref' => $ref,
                ':status' => $status, ':amount' => $amount, ':vat' => $vat,
                ':total' => $total, ':valid_until' => $validUntil,
            ]);
            $newId = (int)$pdo->lastInsertId();

            foreach ($items as $item) {
                $qty = floatval($item['quantity'] ?? 0);
                $price = floatval($item['unit_price'] ?? 0);
                $vatRate = floatval($item['vat_rate'] ?? 0);
                $lineTotal = $qty * $price;
                $pdo->prepare("INSERT INTO quote_items (quote_id, product_id, description, quantity, unit_price, vat_rate, line_total) VALUES (:qid, :pid, :desc, :qty, :price, :vat, :line_total)")
                    ->execute([
                        ':qid' => $newId,
                        ':pid' => intval($item['product_id'] ?? 0) ?: null,
                        ':desc' => $item['description'] ?? '',
                        ':qty' => $qty, ':price' => $price, ':vat' => $vatRate,
                        ':line_total' => $lineTotal,
                    ]);
            }

            $stmt = $pdo->prepare("SELECT q.*, c.name AS client_name FROM quotes q LEFT JOIN clients c ON q.client_id = c.id WHERE q.id = :id");
            $stmt->execute([':id' => $newId]);
            echo json_encode(['success' => true, 'message' => 'Devis ' . $ref . ' cr��', 'quote' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'update':
            $body = readBody();
            $id = intval($body['id'] ?? 0);
            $clientId = intval($body['client_id'] ?? 0);
            $ref = trim($body['ref'] ?? '');
            $status = trim($body['status'] ?? 'brouillon');
            $validUntil = $body['valid_until'] ?? null;
            $items = $body['items'] ?? [];

            $check = $pdo->prepare("SELECT id FROM quotes WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Devis introuvable']); exit; }

            $amount = 0;
            $vat = 0;
            foreach ($items as $item) {
                $qty = floatval($item['quantity'] ?? 0);
                $price = floatval($item['unit_price'] ?? 0);
                $lineTotal = $qty * $price;
                $amount += $lineTotal;
                $vat += $lineTotal * (floatval($item['vat_rate'] ?? 0) / 100);
            }
            $total = $amount + $vat;

            $pdo->prepare("UPDATE quotes SET client_id = :cid, ref = :ref, status = :status, amount = :amount, vat = :vat, total = :total, valid_until = :valid_until, updated_at = NOW() WHERE id = :id AND user_id = :uid")
                ->execute([
                    ':cid' => $clientId, ':ref' => $ref, ':status' => $status,
                    ':amount' => $amount, ':vat' => $vat, ':total' => $total,
                    ':valid_until' => $validUntil, ':id' => $id, ':uid' => $userId,
                ]);

            $pdo->prepare("DELETE FROM quote_items WHERE quote_id = :qid")->execute([':qid' => $id]);
            foreach ($items as $item) {
                $qty = floatval($item['quantity'] ?? 0);
                $price = floatval($item['unit_price'] ?? 0);
                $vatRate = floatval($item['vat_rate'] ?? 0);
                $lineTotal = $qty * $price;
                $pdo->prepare("INSERT INTO quote_items (quote_id, product_id, description, quantity, unit_price, vat_rate, line_total) VALUES (:qid, :pid, :desc, :qty, :price, :vat, :line_total)")
                    ->execute([
                        ':qid' => $id,
                        ':pid' => intval($item['product_id'] ?? 0) ?: null,
                        ':desc' => $item['description'] ?? '',
                        ':qty' => $qty, ':price' => $price, ':vat' => $vatRate,
                        ':line_total' => $lineTotal,
                    ]);
            }

            $stmt = $pdo->prepare("SELECT q.*, c.name AS client_name FROM quotes q LEFT JOIN clients c ON q.client_id = c.id WHERE q.id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Devis mis a jour', 'quote' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'delete':
            $body = readBody();
            $id = intval($body['id'] ?? 0);

            $check = $pdo->prepare("SELECT id FROM quotes WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Devis introuvable']); exit; }

            $pdo->prepare("DELETE FROM quote_items WHERE quote_id = :qid")->execute([':qid' => $id]);
            $pdo->prepare("DELETE FROM quotes WHERE id = :id AND user_id = :uid")->execute([':id' => $id, ':uid' => $userId]);

            echo json_encode(['success' => true, 'message' => 'Devis supprimé']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}