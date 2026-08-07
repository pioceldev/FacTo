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

function productExists(PDO $pdo, int $userId, int $productId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM products WHERE id = ? AND user_id = ?');
    $stmt->execute([$productId, $userId]);
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
        $where[] = '(name LIKE ? OR sku LIKE ? OR description LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $activeOnly = $_GET['active_only'] ?? '0';
    if ($activeOnly === '1') {
        $where[] = 'is_active = 1';
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 15)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT id, name, sku, description, unit_price, vat_rate, is_active, created_at, updated_at
         FROM products
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY name ASC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // Stats globales
    $statsStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active, COALESCE(SUM(unit_price), 0) AS total_value FROM products WHERE user_id = ?'
    );
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch();

    respond([
        'products' => $products,
        'stats' => [
            'total' => (int) ($stats['total'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'total_value' => (float) ($stats['total_value'] ?? 0),
        ],
        'usage' => planUsage($pdo, $userId),
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
        respond(['error' => 'Identifiant de produit manquant'], 422);
    }
    $stmt = $pdo->prepare(
        'SELECT p.*, COALESCE((SELECT SUM(si.quantity) FROM stock si WHERE si.product_id = p.id), 0) AS stock_quantity
         FROM products p WHERE p.id = ? AND p.user_id = ?'
    );
    $stmt->execute([$id, $userId]);
    $product = $stmt->fetch();
    if (!$product) {
        respond(['error' => 'Produit introuvable'], 404);
    }
    respond(['product' => $product]);
}

// ============================================================
// CRÉATION
// ============================================================
if ($action === 'create') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }

    $name = trim((string) ($body['name'] ?? ''));
    $sku = trim((string) ($body['sku'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $unitPrice = (float) ($body['unit_price'] ?? 0);
    $vatRate = (float) ($body['vat_rate'] ?? 0);
    $isActive = !empty($body['is_active']) ? 1 : 0;

    if ($name === '') {
        respond(['error' => 'Le nom du produit est requis'], 422);
    }
    if ($unitPrice < 0) {
        respond(['error' => 'Le prix unitaire ne peut pas être négatif'], 422);
    }
    if ($vatRate < 0 || $vatRate > 100) {
        respond(['error' => 'Le taux de TVA doit être entre 0 et 100'], 422);
    }

    // Quota produits (plan Free : 10 au total en base)
    $usage = planUsage($pdo, $userId);
    if ($usage['product_limit'] < 999999 && $usage['product_count'] >= $usage['product_limit']) {
        respond([
            'error' => 'Quota de produits atteint (' . $usage['product_limit'] . '). Passez au plan Business pour ajouter plus de produits.',
            'usage' => $usage,
        ], 403);
    }

    // Duplication SKU
    if ($sku !== '') {
        $dup = $pdo->prepare('SELECT 1 FROM products WHERE user_id = ? AND sku = ? AND id != ? LIMIT 1');
        $dup->execute([$userId, $sku, 0]);
        if ($dup->fetchColumn()) {
            respond(['error' => 'Un produit avec ce SKU existe déjà'], 409);
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO products (user_id, name, sku, description, unit_price, vat_rate, is_active, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,NOW(),NOW())'
    );
    $stmt->execute([$userId, $name, $sku ?: null, $description ?: null, $unitPrice, $vatRate, $isActive]);
    $productId = (int) $pdo->lastInsertId();

    respond([
        'success' => true,
        'message' => 'Produit ' . $name . ' créé',
        'product' => ['id' => $productId, 'name' => $name],
        'usage' => planUsage($pdo, $userId),
    ], 201);
}

// ============================================================
// MODIFICATION
// ============================================================
if ($action === 'update') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    if (!productExists($pdo, $userId, $id)) {
        respond(['error' => 'Produit introuvable'], 404);
    }

    $name = trim((string) ($body['name'] ?? ''));
    $sku = trim((string) ($body['sku'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $unitPrice = (float) ($body['unit_price'] ?? 0);
    $vatRate = (float) ($body['vat_rate'] ?? 0);
    $isActive = !empty($body['is_active']) ? 1 : 0;

    if ($name === '') {
        respond(['error' => 'Le nom du produit est requis'], 422);
    }
    if ($unitPrice < 0) {
        respond(['error' => 'Le prix unitaire ne peut pas être négatif'], 422);
    }
    if ($vatRate < 0 || $vatRate > 100) {
        respond(['error' => 'Le taux de TVA doit être entre 0 et 100'], 422);
    }

    // Duplication SKU (excluant le produit lui-même)
    if ($sku !== '') {
        $dup = $pdo->prepare('SELECT 1 FROM products WHERE user_id = ? AND sku = ? AND id != ? LIMIT 1');
        $dup->execute([$userId, $sku, $id]);
        if ($dup->fetchColumn()) {
            respond(['error' => 'Un produit avec ce SKU existe déjà'], 409);
        }
    }

    $pdo->prepare(
        'UPDATE products SET name = ?, sku = ?, description = ?, unit_price = ?, vat_rate = ?, is_active = ?, updated_at = NOW() WHERE id = ? AND user_id = ?'
    )->execute([$name, $sku ?: null, $description ?: null, $unitPrice, $vatRate, $isActive, $id, $userId]);

    respond([
        'success' => true,
        'message' => 'Produit ' . $name . ' mis à jour',
    ]);
}

// ============================================================
// ACTIVER / DÉSACTIVER
// ============================================================
if ($action === 'toggle_active') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    if (!productExists($pdo, $userId, $id)) {
        respond(['error' => 'Produit introuvable'], 404);
    }

    $stmt = $pdo->prepare('SELECT is_active FROM products WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $current = (int) $stmt->fetchColumn();
    $newActive = $current ? 0 : 1;

    $pdo->prepare('UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$newActive, $id, $userId]);

    respond([
        'success' => true,
        'message' => $newActive ? 'Produit activé' : 'Produit désactivé',
        'is_active' => $newActive,
    ]);
}

// ============================================================
// SUPPRESSION
// ============================================================
if ($action === 'delete') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    if (!productExists($pdo, $userId, $id)) {
        respond(['error' => 'Produit introuvable'], 404);
    }

    $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $name = $stmt->fetchColumn();

    // Détacher les références (lignes de factures et de devis) en veillant à ne
    // toucher que les lignes de l'utilisateur courant (les ids produits sont globaux).
    $pdo->prepare('UPDATE invoice_items ii JOIN products p ON p.id = ii.product_id SET ii.product_id = NULL WHERE ii.product_id = ? AND p.user_id = ?')->execute([$id, $userId]);
    $pdo->prepare('UPDATE quote_items qi JOIN products p ON p.id = qi.product_id SET qi.product_id = NULL WHERE qi.product_id = ? AND p.user_id = ?')->execute([$id, $userId]);

    $pdo->prepare('DELETE FROM products WHERE id = ? AND user_id = ?')->execute([$id, $userId]);

    respond(['success' => true, 'message' => 'Produit ' . ($name ?: 'supprimé') . ' supprimé']);
}

respond(['error' => 'Action inconnue'], 400);