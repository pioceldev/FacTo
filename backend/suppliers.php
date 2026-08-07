<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/plan.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$userId = planUserId($pdo, (int) $_SESSION['user_id']);

function respond($data, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function readBody()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function esc($value)
{
    return trim((string) $value);
}

function supplierExists(PDO $pdo, int $userId, int $supplierId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM suppliers WHERE id = ? AND user_id = ?');
    $stmt->execute([$supplierId, $userId]);
    return (bool) $stmt->fetchColumn();
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// ============================================================
// LISTE
// ============================================================
if ($action === 'list') {
    $where = ['user_id = ?'];
    $params = [$userId];

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(name LIKE ? OR contact_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 15)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT id, name, contact_name, email, phone, address, created_at, updated_at
         FROM suppliers
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY name ASC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll();

    respond([
        'suppliers' => $suppliers,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ],
    ]);
}

// ============================================================
// DÉTAIL
// ============================================================
if ($action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        respond(['error' => 'Identifiant de fournisseur manquant'], 422);
    }
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $supplier = $stmt->fetch();
    if (!$supplier) {
        respond(['error' => 'Fournisseur introuvable'], 404);
    }
    respond(['supplier' => $supplier]);
}

// ============================================================
// CRÉATION
// ============================================================
if ($action === 'create') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }

    $name = esc($body['name'] ?? '');
    $contactName = esc($body['contact_name'] ?? '');
    $email = trim((string) ($body['email'] ?? ''));
    $phone = esc($body['phone'] ?? '');
    $address = esc($body['address'] ?? '');

    if ($name === '') {
        respond(['error' => 'Le nom du fournisseur est requis'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Adresse e-mail invalide'], 422);
    }

    $dup = $pdo->prepare('SELECT 1 FROM suppliers WHERE user_id = ? AND email = ? AND email != "" LIMIT 1');
    $dup->execute([$userId, $email]);
    if ($email !== '' && (int) $dup->fetchColumn()) {
        respond(['error' => 'Un fournisseur avec cet e-mail existe déjà'], 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO suppliers (user_id, name, contact_name, email, phone, address, created_at, updated_at)
         VALUES (?,?,?,?,?,?,NOW(),NOW())'
    );
    $stmt->execute([$userId, $name, $contactName ?: null, $email ?: null, $phone ?: null, $address ?: null]);
    $supplierId = (int) $pdo->lastInsertId();

    respond([
        'success' => true,
        'message' => 'Fournisseur ' . $name . ' ajouté',
        'supplier' => ['id' => $supplierId, 'name' => $name],
    ], 201);
}

// ============================================================
// MODIFICATION
// ============================================================
if ($action === 'update') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    if (!supplierExists($pdo, $userId, $id)) {
        respond(['error' => 'Fournisseur introuvable'], 404);
    }

    $name = esc($body['name'] ?? '');
    $contactName = esc($body['contact_name'] ?? '');
    $email = trim((string) ($body['email'] ?? ''));
    $phone = esc($body['phone'] ?? '');
    $address = esc($body['address'] ?? '');

    if ($name === '') {
        respond(['error' => 'Le nom du fournisseur est requis'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Adresse e-mail invalide'], 422);
    }

    $dup = $pdo->prepare('SELECT 1 FROM suppliers WHERE user_id = ? AND email = ? AND id != ? AND email != "" LIMIT 1');
    $dup->execute([$userId, $email, $id]);
    if ($email !== '' && (int) $dup->fetchColumn()) {
        respond(['error' => 'Un fournisseur avec cet e-mail existe déjà'], 409);
    }

    $pdo->prepare(
        'UPDATE suppliers SET name = ?, contact_name = ?, email = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ? AND user_id = ?'
    )->execute([$name, $contactName ?: null, $email ?: null, $phone ?: null, $address ?: null, $id, $userId]);

    respond([
        'success' => true,
        'message' => 'Fournisseur ' . $name . ' mis à jour',
    ]);
}

// ============================================================
// SUPPRESSION
// ============================================================
if ($action === 'delete') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    if (!supplierExists($pdo, $userId, $id)) {
        respond(['error' => 'Fournisseur introuvable'], 404);
    }

    $stmt = $pdo->prepare('SELECT name FROM suppliers WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $name = $stmt->fetchColumn();

    $pdo->prepare('DELETE FROM suppliers WHERE id = ? AND user_id = ?')->execute([$id, $userId]);

    respond(['success' => true, 'message' => 'Fournisseur ' . ($name ?: 'supprimé') . ' supprimé']);
}

respond(['error' => 'Action inconnue'], 400);