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

            $where = 'WHERE p.user_id = :uid';
            $params = [':uid' => $userId];
            if ($q !== '') {
                $where .= ' AND (p.transaction_ref LIKE :q OR i.ref LIKE :q OR c.name LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.id LEFT JOIN clients c ON i.client_id = c.id $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            $pages = max(1, (int)ceil($total / $perPage));

            $stmt = $pdo->prepare("
                SELECT p.*, i.ref AS invoice_ref, c.name AS client_name
                FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.id LEFT JOIN clients c ON i.client_id = c.id
                $where ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset
            ");
            $params[':limit'] = $perPage;
            $params[':offset'] = $offset;
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'payments' => $items,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages],
            ]);
            break;

        case 'get':
            $id = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT p.*, i.ref AS invoice_ref, c.name AS client_name, c.email AS client_email
                FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.id LEFT JOIN clients c ON i.client_id = c.id
                WHERE p.id = :id AND p.user_id = :uid
            ");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) { http_response_code(404); echo json_encode(['error' => 'Introuvable']); exit; }
            echo json_encode(['success' => true, 'payment' => $payment]);
            break;

        case 'create':
            $body = readBody();
            $invoiceId = intval($body['invoice_id'] ?? 0);
            $amount = floatval($body['amount'] ?? 0);
            $paymentMethod = trim($body['payment_method'] ?? 'virement');
            $status = trim($body['status'] ?? 'paid');
            $transactionRef = trim($body['transaction_ref'] ?? '');
            $paidAt = $body['paid_at'] ?? null;

            if ($amount <= 0) { http_response_code(400); echo json_encode(['error' => 'Montant invalide']); exit; }

            if ($invoiceId > 0) {
                $invCheck = $pdo->prepare("SELECT id FROM invoices WHERE id = :iid AND user_id = :uid");
                $invCheck->execute([':iid' => $invoiceId, ':uid' => $userId]);
                if (!$invCheck->fetch()) { http_response_code(400); echo json_encode(['error' => 'Facture introuvable']); exit; }
            }

            $stmt = $pdo->prepare("INSERT INTO payments (user_id, invoice_id, amount, payment_method, status, transaction_ref, paid_at) VALUES (:uid, :iid, :amount, :method, :status, :txref, :paid_at)");
            $stmt->execute([
                ':uid' => $userId, ':iid' => $invoiceId ?: null,
                ':amount' => $amount, ':method' => $paymentMethod,
                ':status' => $status, ':txref' => $transactionRef ?: null,
                ':paid_at' => $paidAt,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT p.*, i.ref AS invoice_ref FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.id WHERE p.id = :id");
            $stmt->execute([':id' => $newId]);
            echo json_encode(['success' => true, 'message' => 'Paiement enregistré', 'payment' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'update':
            $body = readBody();
            $id = intval($body['id'] ?? 0);
            $amount = floatval($body['amount'] ?? 0);
            $paymentMethod = trim($body['payment_method'] ?? 'virement');
            $status = trim($body['status'] ?? 'paid');
            $transactionRef = trim($body['transaction_ref'] ?? '');
            $paidAt = $body['paid_at'] ?? null;

            $check = $pdo->prepare("SELECT id FROM payments WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Paiement introuvable']); exit; }

            $pdo->prepare("UPDATE payments SET amount = :amount, payment_method = :method, status = :status, transaction_ref = :txref, paid_at = :paid_at, updated_at = NOW() WHERE id = :id AND user_id = :uid")
                ->execute([
                    ':amount' => $amount, ':method' => $paymentMethod,
                    ':status' => $status, ':txref' => $transactionRef ?: null,
                    ':paid_at' => $paidAt, ':id' => $id, ':uid' => $userId,
                ]);

            $stmt = $pdo->prepare("SELECT p.*, i.ref AS invoice_ref FROM payments p LEFT JOIN invoices i ON p.invoice_id = i.id WHERE p.id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Paiement mis a jour', 'payment' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'delete':
            $body = readBody();
            $id = intval($body['id'] ?? 0);

            $check = $pdo->prepare("SELECT id FROM payments WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Paiement introuvable']); exit; }

            $pdo->prepare("DELETE FROM payments WHERE id = :id AND user_id = :uid")->execute([':id' => $id, ':uid' => $userId]);

            echo json_encode(['success' => true, 'message' => 'Paiement supprimé']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}