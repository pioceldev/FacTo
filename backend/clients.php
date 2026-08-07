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

function clientExists(PDO $pdo, int $userId, int $clientId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$clientId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function getClient(PDO $pdo, int $userId, int $clientId)
{
    $stmt = $pdo->prepare(
        'SELECT c.*,
                COALESCE((SELECT COUNT(*) FROM invoices i WHERE i.client_id = c.id), 0) AS invoices_count,
                COALESCE((SELECT COUNT(*) FROM invoices i WHERE i.client_id = c.id AND i.status = "payee"), 0) AS paid_invoices_count,
                COALESCE((SELECT SUM(i.total) FROM invoices i WHERE i.client_id = c.id AND i.status = "payee"), 0) AS total_billed
         FROM clients c
         WHERE c.id = ? AND c.user_id = ?'
    );
    $stmt->execute([$clientId, $userId]);
    return $stmt->fetch();
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
        $where[] = '(name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 15)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, company, address, city, country, created_at
         FROM clients
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY name ASC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $clients = $stmt->fetchAll();

    // Stats globales (indépendantes du filtre courant)
    $stats = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM clients WHERE user_id = ?) AS total_clients,
            (SELECT COUNT(DISTINCT client_id) FROM invoices WHERE user_id = ? AND client_id IS NOT NULL) AS with_invoices,
            (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = ? AND status = "payee") AS total_billed'
    );
    $stats->execute([$userId, $userId, $userId]);
    $s = $stats->fetch();

    respond([
        'clients' => $clients,
        'stats' => [
            'total' => (int) ($s['total_clients'] ?? 0),
            'with_invoices' => (int) ($s['with_invoices'] ?? 0),
            'total_billed' => (float) ($s['total_billed'] ?? 0),
        ],
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
        respond(['error' => 'Identifiant de client manquant'], 422);
    }
    $client = getClient($pdo, $userId, $id);
    if (!$client) {
        respond(['error' => 'Client introuvable'], 404);
    }
    respond(['client' => $client]);
}

// ============================================================
// CRÉATION
// ============================================================
if ($action === 'create') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }

    $name = esc($body['name'] ?? '');
    $email = trim((string) ($body['email'] ?? ''));
    $company = esc($body['company'] ?? '');
    $phone = esc($body['phone'] ?? '');
    $address = esc($body['address'] ?? '');
    $city = esc($body['city'] ?? '');
    $zip = esc($body['zip'] ?? '');
    $country = esc($body['country'] ?? '');

    if ($name === '') {
        respond(['error' => 'Le nom du client est requis'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Adresse e-mail invalide'], 422);
    }

    // Lien de la duplication éventuelle (email déjà utilisé par un AUTRE client)
    $dup = $pdo->prepare('SELECT 1 FROM clients WHERE user_id = ? AND email = ? AND email != "" LIMIT 1');
    $dup->execute([$userId, $email]);
    if ($email !== '' && (int) $dup->fetchColumn()) {
        respond(['error' => 'Un client avec cet e-mail existe déjà'], 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO clients (user_id, name, email, phone, company, address, city, zip, country, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())'
    );
    $stmt->execute([$userId, $name, $email, $phone, $company, $address, $city, $zip, $country]);
    $clientId = (int) $pdo->lastInsertId();

    respond([
        'success' => true,
        'message' => 'Client ' . $name . ' ajouté',
        'client' => getClient($pdo, $userId, $clientId),
    ], 201);
}

// ============================================================
// MODIFICATION
// ============================================================
if ($action === 'update') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    if (!clientExists($pdo, $userId, $id)) {
        respond(['error' => 'Client introuvable'], 404);
    }

    $name = esc($body['name'] ?? '');
    $email = trim((string) ($body['email'] ?? ''));
    $company = esc($body['company'] ?? '');
    $phone = esc($body['phone'] ?? '');
    $address = esc($body['address'] ?? '');
    $city = esc($body['city'] ?? '');
    $zip = esc($body['zip'] ?? '');
    $country = esc($body['country'] ?? '');

    if ($name === '') {
        respond(['error' => 'Le nom du client est requis'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Adresse e-mail invalide'], 422);
    }

    $dup = $pdo->prepare('SELECT 1 FROM clients WHERE user_id = ? AND email = ? AND id != ? AND email != "" LIMIT 1');
    $dup->execute([$userId, $email, $id]);
    if ($email !== '' && (int) $dup->fetchColumn()) {
        respond(['error' => 'Un client avec cet e-mail existe déjà'], 409);
    }

    $pdo->prepare(
        'UPDATE clients SET name = ?, email = ?, phone = ?, company = ?, address = ?, city = ?, zip = ?, country = ?, updated_at = NOW() WHERE id = ? AND user_id = ?'
    )->execute([$name, $email, $phone, $company, $address, $city, $zip, $country, $id, $userId]);

    respond([
        'success' => true,
        'message' => 'Client ' . $name . ' mis à jour',
        'client' => getClient($pdo, $userId, $id),
    ]);
}

// ============================================================
// SUPPRESSION
// ============================================================
if ($action === 'delete') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    if (!clientExists($pdo, $userId, $id)) {
        respond(['error' => 'Client introuvable'], 404);
    }

    // Détacher les factures liées (ne pas supprimer les factures)
    $pdo->prepare('UPDATE invoices SET client_id = NULL WHERE client_id = ? AND user_id = ?')->execute([$id, $userId]);

    $nameStmt = $pdo->prepare('SELECT name FROM clients WHERE id = ? AND user_id = ?');
    $nameStmt->execute([$id, $userId]);
    $name = $nameStmt->fetchColumn();

    $deleted = $pdo->prepare('DELETE FROM clients WHERE id = ? AND user_id = ?')->execute([$id, $userId]);

    if (!$deleted) {
        respond(['error' => 'La suppression a échoué'], 500);
    }

    respond(['success' => true, 'message' => 'Client ' . ($name ?: 'supprimé') . ' supprimé']);
}

respond(['error' => 'Action inconnue'], 400);
