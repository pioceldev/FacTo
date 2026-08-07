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

// Migration best-effort : colonne template (modèles de devis) — compatible MySQL 5.7+
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'template'");
    if (!$chk->fetchColumn()) {
        $pdo->exec("ALTER TABLE quotes ADD COLUMN template VARCHAR(50) NOT NULL DEFAULT 'classic' AFTER ref");
    }
} catch (PDOException $e) { /* silencieux */ }

// Migration best-effort : stock_reserved (le devis accepté réserve le stock)
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'stock_reserved'");
    if (!$chk->fetchColumn()) {
        $pdo->exec("ALTER TABLE quotes ADD COLUMN stock_reserved TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
} catch (PDOException $e) { /* silencieux */ }

// Migration best-effort : converted_invoice_id (devis converti en facture)
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes' AND COLUMN_NAME = 'converted_invoice_id'");
    if (!$chk->fetchColumn()) {
        $pdo->exec('ALTER TABLE quotes ADD COLUMN converted_invoice_id INT DEFAULT NULL AFTER stock_reserved');
    }
} catch (PDOException $e) { /* silencieux */ }

/* ============================================================
   GESTION DU STOCK LIÉ AUX DEVIS
   Un devis « accepté » réserve les quantités : le stock physique est
   diminué comme pour une facture émise. Il est remis si le devis
   change de statut, est supprimé, ou est converti en facture
   (la facture reprend alors la réservation).
   ============================================================ */

// Normalisation des statuts (avec ou sans accents)
function quoteNormalizeStatus($s)
{
    $s = strtolower(trim((string) $s));
    $s = str_replace(['é', 'è', 'ê'], ['e', 'e', 'e'], $s);
    return $s;
}

// Stock restant (physique) d'un produit
function quoteAvailableStock(PDO $pdo, int $userId, int $productId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(quantity, 0) FROM stock WHERE product_id = ? AND user_id = ?');
    $stmt->execute([$productId, $userId]);
    return (float) $stmt->fetchColumn();
}

// Vérifie qu'aucune ligne ne dépasse le stock restant. Retourne null si OK.
function quoteValidateStock(PDO $pdo, int $userId, array $items): ?array
{
    foreach ($items as $item) {
        $pid = intval($item['product_id'] ?? 0);
        $qty = floatval($item['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $available = quoteAvailableStock($pdo, $userId, $pid);
        if ($qty > $available + 0.0001) {
            $name = '';
            $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ? AND user_id = ?');
            $stmt->execute([$pid, $userId]);
            $name = (string) $stmt->fetchColumn();
            return [
                'error' => 'Stock insuffisant pour "' . ($name ?: 'produit #' . $pid) . '" : ' .
                           rtrim(rtrim(number_format($qty, 2, ',', ' '), '0'), ',') . ' demandé(s), ' .
                           rtrim(rtrim(number_format($available, 2, ',', ' '), '0'), ',') . ' restant(s).',
                'product' => $name,
            ];
        }
    }
    return null;
}

// Ajuste le stock physique : -1 pour réserver, +1 pour libérer
function quoteAdjustStock(PDO $pdo, int $userId, array $items, float $sign): void
{
    foreach ($items as $item) {
        $pid = intval($item['product_id'] ?? 0);
        $qty = floatval($item['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $stmt = $pdo->prepare('UPDATE stock SET quantity = GREATEST(0, quantity + ?), updated_at = NOW() WHERE product_id = ? AND user_id = ?');
        $stmt->execute([$sign * $qty, $pid, $userId]);
    }
}

// Normalise un item devis en format stock (product_id + quantity)
function quoteStockItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $out[] = ['product_id' => intval($item['product_id'] ?? 0), 'quantity' => floatval($item['quantity'] ?? 0)];
    }
    return $out;
}

// Quota centralisé (plan.php) : factures + devis + produits selon le plan.
function quoteUsageInfo(PDO $pdo, int $userId)
{
    return planUsage($pdo, $userId);
}

// Prochaine référence de facture (identique à invoices.php)
function quoteNextInvoiceRef(PDO $pdo, int $userId)
{
    $year = date('Y');
    $stmt = $pdo->prepare('SELECT ref FROM invoices WHERE user_id = ? AND ref LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId, 'FAC-' . $year . '-%']);
    $last = $stmt->fetchColumn();
    $n = 1;
    if ($last) {
        $suffix = substr(strrchr((string) $last, '-') ?: '-0', 1);
        $n = ((int) $suffix) + 1;
    }
    return sprintf('FAC-%d-%03d', $year, $n);
}

// Modèles de devis autorisés
$ALLOWED_TEMPLATES = ['classic', 'moderne', 'minimal'];

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
                'usage' => quoteUsageInfo($pdo, $userId),
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

        case 'clients':
            // Sélecteur client du formulaire devis (devis.js -> loadClients())
            $stmt = $pdo->prepare("SELECT id, name, company, email, phone FROM clients WHERE user_id = :uid ORDER BY name");
            $stmt->execute([':uid' => $userId]);
            echo json_encode(['success' => true, 'clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'products':
            // Sélecteur produit du formulaire devis (devis.js -> loadProducts())
            $stmt = $pdo->prepare("SELECT id, name, sku, unit_price, vat_rate FROM products WHERE user_id = :uid AND is_active = 1 ORDER BY name");
            $stmt->execute([':uid' => $userId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Stock restant pour chaque produit (réservé si le devis est accepté)
            foreach ($products as &$p) {
                $p['stock_quantity'] = quoteAvailableStock($pdo, $userId, (int) $p['id']);
            }
            unset($p);
            echo json_encode(['success' => true, 'products' => $products]);
            break;

        case 'create':
            $body = readBody();
            if (!csrfValidateToken($body['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
            $clientId = intval($body['client_id'] ?? 0);
            $ref = trim($body['ref'] ?? '');
            $template = trim($body['template'] ?? 'classic');
            if (!in_array($template, $ALLOWED_TEMPLATES, true)) { $template = 'classic'; }
            $status = trim($body['status'] ?? 'brouillon');
            $validUntil = $body['valid_until'] ?? null;
            $items = $body['items'] ?? [];

            // Quota devis mensuel (plan Free : 3/mois)
            $usage = quoteUsageInfo($pdo, $userId);
            if ($usage['quote_limit'] < 999999 && $usage['quote_count'] >= $usage['quote_limit']) {
                http_response_code(403);
                echo json_encode([
                    'error' => 'Quota de devis mensuel atteint (' . $usage['quote_limit'] . '). Passez au plan Business pour continuer.',
                    'usage' => $usage,
                ]);
                exit;
            }

            // Le client est optionnel (devis sans client). S'il est fourni, il doit appartenir à l'utilisateur.
            if ($clientId > 0) {
                $clientCheck = $pdo->prepare("SELECT id FROM clients WHERE id = :cid AND user_id = :uid");
                $clientCheck->execute([':cid' => $clientId, ':uid' => $userId]);
                if (!$clientCheck->fetch()) { http_response_code(400); echo json_encode(['error' => 'Client introuvable']); exit; }
            }

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

            // Un devis accepté réserve les quantités en stock (validation + déduction atomiques)
            $isAccepted = quoteNormalizeStatus($status) === 'accepte';
            $stockItems = quoteStockItems($items);

            $pdo->beginTransaction();
            try {
                if ($isAccepted) {
                    $stockErr = quoteValidateStock($pdo, $userId, $stockItems);
                    if ($stockErr) {
                        $pdo->rollBack();
                        http_response_code(409);
                        echo json_encode(['error' => $stockErr['error']]);
                        exit;
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO quotes (user_id, client_id, ref, template, status, stock_reserved, amount, vat, total, valid_until, created_at) VALUES (:uid, :cid, :ref, :template, :status, :reserved, :amount, :vat, :total, :valid_until, NOW())");
                $stmt->execute([
                    ':uid' => $userId, ':cid' => $clientId ?: null, ':ref' => $ref, ':template' => $template,
                    ':status' => $status, ':reserved' => $isAccepted ? 1 : 0, ':amount' => $amount, ':vat' => $vat,
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

                if ($isAccepted) {
                    quoteAdjustStock($pdo, $userId, $stockItems, -1);
                }

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                throw $e;
            }

            $stmt = $pdo->prepare("SELECT q.*, c.name AS client_name FROM quotes q LEFT JOIN clients c ON q.client_id = c.id WHERE q.id = :id");
            $stmt->execute([':id' => $newId]);
            echo json_encode(['success' => true, 'message' => 'Devis ' . $ref . ' créé', 'quote' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'update':
            $body = readBody();
            if (!csrfValidateToken($body['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
            $id = intval($body['id'] ?? 0);
            $clientId = intval($body['client_id'] ?? 0);

            // Si un client est fourni, il doit appartenir à l'utilisateur courant.
            if ($clientId > 0) {
                $clientCheck = $pdo->prepare("SELECT id FROM clients WHERE id = :cid AND user_id = :uid");
                $clientCheck->execute([':cid' => $clientId, ':uid' => $userId]);
                if (!$clientCheck->fetch()) { http_response_code(400); echo json_encode(['error' => 'Client introuvable']); exit; }
            }
            $ref = trim($body['ref'] ?? '');
            $template = trim($body['template'] ?? 'classic');
            if (!in_array($template, $ALLOWED_TEMPLATES, true)) { $template = 'classic'; }
            $status = trim($body['status'] ?? 'brouillon');
            $validUntil = $body['valid_until'] ?? null;
            $items = $body['items'] ?? [];

            // Récupère le devis existant (statut + réservation actuelle + items)
            $check = $pdo->prepare("SELECT * FROM quotes WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Devis introuvable']); exit; }
            if (!empty($existing['converted_invoice_id'])) {
                http_response_code(422);
                echo json_encode(['error' => 'Ce devis a déjà été converti en facture et ne peut plus être modifié']);
                exit;
            }

            // Quota devis mensuel en édition : si le quota est atteint, on n'autorise
            // que la modification d'un devis de ce mois qui compte déjà dans le quota.
            $usage = quoteUsageInfo($pdo, $userId);
            if ($usage['quote_limit'] < 999999 && $usage['quote_count'] >= $usage['quote_limit']) {
                $createdThisMonth = (strtotime((string) $existing['created_at']) ?: 0) >= strtotime(date('Y-m-01'));
                $countsTowardQuota = $createdThisMonth && strtolower(trim((string) $existing['status'])) !== 'brouillon';
                if (!$countsTowardQuota) {
                    http_response_code(403);
                    echo json_encode([
                        'error' => 'Quota de devis mensuel atteint (' . $usage['quote_limit'] . '). Passez au plan Business pour continuer.',
                        'usage' => $usage,
                    ]);
                    exit;
                }
            }
            $itemsStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = :qid ORDER BY id");
            $itemsStmt->execute([':qid' => $id]);
            $existing['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

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

            // Gestion de la réservation stock (statut accepté = réserve)
            $wasAccepted = quoteNormalizeStatus($existing['status']) === 'accepte';
            $isAccepted = quoteNormalizeStatus($status) === 'accepte';
            $oldStockItems = quoteStockItems($existing['items'] ?? []);
            $newStockItems = quoteStockItems($items);

            $pdo->beginTransaction();
            try {
                // Libère l'ancienne réservation si le devis était accepté
                if ($wasAccepted) {
                    quoteAdjustStock($pdo, $userId, $oldStockItems, 1);
                }
                // Validation + nouvelle réservation si le nouveau statut est accepté
                if ($isAccepted) {
                    $stockErr = quoteValidateStock($pdo, $userId, $newStockItems);
                    if ($stockErr) {
                        // Le rollback annule la libération effectuée ci-dessus
                        $pdo->rollBack();
                        http_response_code(409);
                        echo json_encode(['error' => $stockErr['error']]);
                        exit;
                    }
                }

                $pdo->prepare("UPDATE quotes SET client_id = :cid, ref = :ref, template = :template, status = :status, stock_reserved = :reserved, amount = :amount, vat = :vat, total = :total, valid_until = :valid_until, updated_at = NOW() WHERE id = :id AND user_id = :uid")
                    ->execute([
                        ':cid' => $clientId ?: null, ':ref' => $ref, ':template' => $template, ':status' => $status,
                        ':reserved' => $isAccepted ? 1 : 0, ':amount' => $amount, ':vat' => $vat, ':total' => $total,
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

                // Nouvelle réservation
                if ($isAccepted) {
                    quoteAdjustStock($pdo, $userId, $newStockItems, -1);
                }

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                throw $e;
            }

            $stmt = $pdo->prepare("SELECT q.*, c.name AS client_name FROM quotes q LEFT JOIN clients c ON q.client_id = c.id WHERE q.id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Devis mis a jour', 'quote' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'delete':
            $body = readBody();
            if (!csrfValidateToken($body['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
            $id = intval($body['id'] ?? 0);

            // Récupère le devis (réservation + items) pour libérer le stock
            $check = $pdo->prepare("SELECT * FROM quotes WHERE id = :id AND user_id = :uid");
            $check->execute([':id' => $id, ':uid' => $userId]);
            $quote = $check->fetch(PDO::FETCH_ASSOC);
            if (!$quote) { http_response_code(404); echo json_encode(['error' => 'Devis introuvable']); exit; }
            $itemsStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = :qid");
            $itemsStmt->execute([':qid' => $id]);
            $quoteItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            try {
                // Libère le stock si le devis accepté l'avait réservé
                if (intval($quote['stock_reserved'] ?? 0) === 1) {
                    quoteAdjustStock($pdo, $userId, quoteStockItems($quoteItems), 1);
                }
                $pdo->prepare("DELETE FROM quote_items WHERE quote_id = :qid")->execute([':qid' => $id]);
                $pdo->prepare("DELETE FROM quotes WHERE id = :id AND user_id = :uid")->execute([':id' => $id, ':uid' => $userId]);
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'Devis supprimé']);
            break;

        case 'convert':
            // Convertit un devis en facture : le stock réservé par le devis
            // accepté est transféré à la facture (la facture le déduit), et le
            // devis est marqué comme converti (plus modifiable).
            $body = readBody();
            if (!csrfValidateToken($body['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
            $id = intval($body['id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM quotes WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$quote) { http_response_code(404); echo json_encode(['error' => 'Devis introuvable']); exit; }
            if (!empty($quote['converted_invoice_id'])) {
                http_response_code(422);
                echo json_encode(['error' => 'Ce devis a déjà été converti en facture']);
                exit;
            }

            $itemsStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = :qid ORDER BY id");
            $itemsStmt->execute([':qid' => $id]);
            $quoteItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!count($quoteItems)) {
                http_response_code(422);
                echo json_encode(['error' => 'Ce devis ne contient aucune ligne à convertir']);
                exit;
            }

            // Le quota mensuel de factures s'applique aussi à la conversion
            $usage = quoteUsageInfo($pdo, $userId);
            if ($usage['invoice_limit'] < 999999 && $usage['invoice_count'] >= $usage['invoice_limit']) {
                http_response_code(403);
                echo json_encode([
                    'error' => 'Quota de factures mensuel atteint (' . $usage['invoice_limit'] . '). Passez à un plan supérieur pour continuer.',
                    'usage' => $usage,
                ]);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. Libère la réservation du devis (le stock redevient disponible)
                if (intval($quote['stock_reserved'] ?? 0) === 1) {
                    quoteAdjustStock($pdo, $userId, quoteStockItems($quoteItems), 1);
                }

                // 2. Crée la facture émise (déduit le stock via la logique facture)
                $ref = quoteNextInvoiceRef($pdo, $userId);
                $dueDate = date('Y-m-d', strtotime('+30 days'));
                if (!empty($quote['valid_until'])) {
                    $dueDate = date('Y-m-d', strtotime($quote['valid_until']));
                }

                $amount = 0.0;
                $vat = 0.0;
                foreach ($quoteItems as $item) {
                    $amount += floatval($item['line_total'] ?? 0);
                    $vat += round(floatval($item['line_total'] ?? 0) * floatval($item['vat_rate'] ?? 0) / 100, 2);
                }
                $total = round($amount + $vat, 2);

                $invoiceStmt = $pdo->prepare(
                    'INSERT INTO invoices (user_id, client_id, ref, amount, vat, discount, total, status, due_date, issued_at, notes, created_at)
                     VALUES (?,?,?,?,?,0,?,?,?,NOW(),?,NOW())'
                );
                $invoiceStmt->execute([
                    $userId,                 // user_id
                    $quote['client_id'] ?: null, // client_id
                    $ref,                    // ref
                    round($amount, 2),       // amount
                    round($vat, 2),          // vat
                    $total,                  // total
                    'en_attente',            // status
                    $dueDate,                // due_date
                    'Converti depuis le devis ' . $quote['ref'], // notes
                ]);
                $invoiceId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, vat_rate, line_total) VALUES (?,?,?,?,?,?,?)');
                foreach ($quoteItems as $item) {
                    $itemStmt->execute([
                        $invoiceId,
                        $item['product_id'] ?: null,
                        $item['description'] ?? '',
                        $item['quantity'],
                        $item['unit_price'],
                        $item['vat_rate'],
                        $item['line_total'],
                    ]);
                }

                // 3. Déduit le stock (validation atomique avec la logique facture)
                $stockErr = quoteValidateStock($pdo, $userId, quoteStockItems($quoteItems));
                if ($stockErr) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['error' => $stockErr['error']]);
                    exit;
                }
                quoteAdjustStock($pdo, $userId, quoteStockItems($quoteItems), -1);
                // La facture porte la déduction (pour qu'annuler/supprimer la
                // facture remette automatiquement le stock via invoices.php)
                $pdo->prepare('UPDATE invoices SET stock_deducted = 1 WHERE id = ?')->execute([$invoiceId]);

                // 4. Marque le devis comme converti
                $pdo->prepare('UPDATE quotes SET converted_invoice_id = :iid, stock_reserved = 0, status = :status, updated_at = NOW() WHERE id = :id AND user_id = :uid')
                    ->execute([':iid' => $invoiceId, ':status' => $quote['status'], ':id' => $id, ':uid' => $userId]);

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Devis converti en facture ' . $ref,
                'invoice_id' => $invoiceId,
                'invoice_ref' => $ref,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de donnees', 'detail' => $e->getMessage()]);
}