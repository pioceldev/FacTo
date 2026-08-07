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

// Ensure discount column exists (best-effort, MySQL 5.7 compatible)
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'discount'");
    if (!(int) $stmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vat');
    }
} catch (Exception $e) {
    // ignore
}

// Ensure stock_deducted column exists : indique si la facture a déduit le stock
// (brouillons et annulées : 0 — factures émises/payées : 1)
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'stock_deducted'");
    if (!(int) $stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN stock_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
} catch (Exception $e) {
    // ignore
}

function respond($data, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/* ============================================================
   GESTION DU STOCK LIÉ AUX FACTURES
   Le stock physique (table stock.quantity) EST la source de vérité :
   - il est diminué quand une facture est émise (statut différent de
     brouillon/annulée) ;
   - il est remis automatiquement si la facture est modifiée, annulée
     ou supprimée ;
   - la quantité facturée ne peut jamais dépasser le stock restant.
   Toute validation + déduction se fait DANS la même transaction.
   ============================================================ */

// Stock restant (physique) d'un produit
function availableStock(PDO $pdo, int $userId, int $productId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(quantity, 0) FROM stock WHERE product_id = ? AND user_id = ?');
    $stmt->execute([$productId, $userId]);
    return (float) $stmt->fetchColumn();
}

// Vérifie qu'aucune ligne ne dépasse le stock restant. Retourne null si OK,
// sinon ['error' => message].
function validateStockLines(PDO $pdo, int $userId, array $lines): ?array
{
    foreach ($lines as $line) {
        $pid = (int) ($line['product_id'] ?? 0);
        $qty = (float) ($line['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $available = availableStock($pdo, $userId, $pid);
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

// Ajuste le stock physique : $sign = -1 pour déduire, +1 pour remettre.
function adjustStock(PDO $pdo, int $userId, array $lines, float $sign): void
{
    foreach ($lines as $line) {
        $pid = (int) ($line['product_id'] ?? 0);
        $qty = (float) ($line['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $stmt = $pdo->prepare('UPDATE stock SET quantity = GREATEST(0, quantity + ?), updated_at = NOW() WHERE product_id = ? AND user_id = ?');
        $stmt->execute([$sign * $qty, $pid, $userId]);
    }
}

function usageInfo(PDO $pdo, int $userId)
{
    // Quotas centralisés dans plan.php (Free : 3 factures/mois, 3 devis/mois, 10 produits)
    return planUsage($pdo, $userId);
}

function getInvoiceStats(PDO $pdo, int $userId)
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = ? AND status = "payee" AND paid_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'
    );
    $stmt->execute([$userId]);
    $monthlyRevenue = (float) $stmt->fetchColumn();

    $counts = ['brouillon' => 0, 'en_attente' => 0, 'payee' => 0, 'en_retard' => 0, 'annulee' => 0];
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM invoices WHERE user_id = ? GROUP BY status');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }

    $pending = $stmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM invoices WHERE user_id = ? AND status IN ("en_attente", "en_retard")');
    $stmt->execute([$userId]);
    $pendingAmount = (float) $stmt->fetchColumn();

    return [
        'monthly_revenue' => $monthlyRevenue,
        'counts' => $counts,
        'pending_amount' => $pendingAmount,
    ];
}

function nextInvoiceRef(PDO $pdo, int $userId)
{
    $year = date('Y');
    // Basée sur la dernière référence existante (pas de COUNT) : pas de doublon
    // après suppression de factures, et fonctionne au-delà de 999.
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

function readBody()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function findClient(PDO $pdo, int $userId, $clientId): ?int
{
    $clientId = (int) $clientId;
    if (!$clientId) return null;
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$clientId, $userId]);
    return $stmt->fetchColumn() ? $clientId : null;
}

function parseItems($items, $discount = 0)
{
    if (!is_array($items) || !count($items)) {
        return ['error' => 'Au moins une ligne de facture est requise'];
    }

    $lines = [];
    $amount = 0.0;
    $vat = 0.0;

    $discount = max(0, (float) $discount);

    foreach ($items as $index => $item) {
        if (!is_array($item)) continue;

        $description = trim((string) ($item['description'] ?? ''));
        $quantity = (float) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $vatRate = (float) ($item['vat_rate'] ?? 0);

        // Lignes vides ignorées (l'utilisateur a laissé une rangée en blanc)
        if ($description === '' && $quantity <= 0 && $unitPrice <= 0 && $vatRate <= 0) {
            continue;
        }

        $n = $index + 1;
        if ($description === '') {
            return ['error' => 'Ligne ' . $n . ' : la description est requise'];
        }
        if ($quantity < 0 || $unitPrice < 0 || $vatRate < 0) {
            return ['error' => 'Ligne ' . $n . ' : montants invalides'];
        }

        $lineTotal = round($quantity * $unitPrice, 2);
        $lineVat = round($lineTotal * $vatRate / 100, 2);
        $amount += $lineTotal;
        $vat += $lineVat;

        $lines[] = [
            'product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'vat_rate' => $vatRate,
            'line_total' => $lineTotal,
        ];
    }

    if (!count($lines)) {
        return ['error' => 'Au moins une ligne de facture est requise'];
    }

    $amount = round($amount, 2);
    $vat = round($vat, 2);
    $discount = round(min($discount, $amount + $vat), 2);

    return [
        'lines' => $lines,
        'amount' => $amount,
        'vat' => $vat,
        'discount' => $discount,
        'total' => round($amount + $vat - $discount, 2),
    ];
}

function normalizeStatus($status)
{
    $allowed = ['brouillon', 'en_attente', 'payee', 'en_retard', 'annulee'];
    $status = strtolower(trim((string) $status));
    return in_array($status, $allowed, true) ? $status : 'brouillon';
}

function recordPayment(PDO $pdo, int $userId, int $invoiceId, $amount, $paymentMethod)
{
    $ref = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare('INSERT INTO payments (user_id, invoice_id, amount, payment_method, status, transaction_ref, paid_at, created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([$userId, $invoiceId, $amount, $paymentMethod, 'paid', $ref]);
    return $ref;
}

function getInvoice(PDO $pdo, int $userId, int $invoiceId)
{
    $stmt = $pdo->prepare(
        'SELECT i.*, COALESCE(c.name, "Client inconnu") AS client_name,
                c.email AS client_email, c.company AS client_company,
                c.phone AS client_phone, c.address AS client_address, c.city AS client_city
         FROM invoices i
         LEFT JOIN clients c ON c.id = i.client_id
         WHERE i.id = ? AND i.user_id = ?'
    );
    $stmt->execute([$invoiceId, $userId]);
    $invoice = $stmt->fetch();
    if (!$invoice) return null;

    $itemsStmt = $pdo->prepare('SELECT id, product_id, description, quantity, unit_price, vat_rate, line_total FROM invoice_items WHERE invoice_id = ? ORDER BY id');
    $itemsStmt->execute([$invoiceId]);
    $invoice['items'] = $itemsStmt->fetchAll();

    $paymentsStmt = $pdo->prepare('SELECT id, amount, payment_method, status, transaction_ref, paid_at FROM payments WHERE invoice_id = ? ORDER BY paid_at DESC');
    $paymentsStmt->execute([$invoiceId]);
    $invoice['payments'] = $paymentsStmt->fetchAll();

    return $invoice;
}

function notify(PDO $pdo, int $userId, string $title, string $body, string $type = 'paiement')
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    $stmt->execute([$userId, $title, $body, $type]);
}

function insertItems(PDO $pdo, int $invoiceId, array $lines)
{
    $stmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, vat_rate, line_total) VALUES (?,?,?,?,?,?,?)');
    foreach ($lines as $line) {
        $stmt->execute([
            $invoiceId,
            $line['product_id'],
            $line['description'],
            $line['quantity'],
            $line['unit_price'],
            $line['vat_rate'],
            $line['line_total'],
        ]);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// ============================================================
// LISTE
// ============================================================
if ($action === 'list') {
    $where = ['i.user_id = ?'];
    $params = [$userId];

    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '' && $status !== 'all') {
        $where[] = 'i.status = ?';
        $params[] = $status;
    }

    $clientId = (int) ($_GET['client_id'] ?? 0);
    if ($clientId > 0) {
        $where[] = 'i.client_id = ?';
        $params[] = $clientId;
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(i.ref LIKE ? OR c.name LIKE ? OR i.total LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $sqlWhere = implode(' AND ', $where);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE ' . $sqlWhere);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 15)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT i.id, i.ref, i.client_id, COALESCE(c.name, "Client inconnu") AS client_name,
                i.amount, i.vat, i.total, i.status, i.due_date, i.issued_at, i.paid_at, i.created_at
         FROM invoices i
         LEFT JOIN clients c ON c.id = i.client_id
         WHERE ' . $sqlWhere . '
         ORDER BY i.due_date DESC, i.id DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    respond([
        'invoices' => $invoices,
        'stats' => getInvoiceStats($pdo, $userId),
        'usage' => usageInfo($pdo, $userId),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ],
    ]);
}

// ============================================================
// CLIENTS & PRODUITS (sélecteurs du formulaire)
// ============================================================
if ($action === 'clients') {
    $stmt = $pdo->prepare('SELECT id, name, company, email, phone FROM clients WHERE user_id = ? ORDER BY name');
    $stmt->execute([$userId]);
    respond(['clients' => $stmt->fetchAll()]);
}

if ($action === 'products') {
    $stmt = $pdo->prepare('SELECT id, name, sku, unit_price, vat_rate FROM products WHERE user_id = ? AND is_active = 1 ORDER BY name');
    $stmt->execute([$userId]);
    $products = $stmt->fetchAll();
    // Stock disponible pour chaque produit (pour le formulaire de facture)
    foreach ($products as &$p) {
        $p['stock_quantity'] = availableStock($pdo, $userId, (int) $p['id']);
    }
    unset($p);
    respond(['products' => $products]);
}

// ============================================================
// DÉTAIL
// ============================================================
if ($action === 'get') {
    $invoiceId = (int) ($_GET['id'] ?? 0);
    if (!$invoiceId) {
        respond(['error' => 'Identifiant de facture manquant'], 422);
    }
    $invoice = getInvoice($pdo, $userId, $invoiceId);
    if (!$invoice) {
        respond(['error' => 'Facture introuvable'], 404);
    }
    respond(['invoice' => $invoice]);
}

// ============================================================
// CRÉATION
// ============================================================
if ($action === 'create') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }

    $clientId = findClient($pdo, $userId, $body['client_id'] ?? 0);
    if (isset($body['client_id']) && (int) $body['client_id'] !== 0 && $clientId === null) {
        respond(['error' => 'Client invalide'], 422);
    }

    $dueDate = trim((string) ($body['due_date'] ?? ''));
    if ($dueDate === '' || strtotime($dueDate) === false) {
        respond(['error' => 'Date d\'échéance requise'], 422);
    }

    $usage = usageInfo($pdo, $userId);
    if ($usage['invoice_limit'] < 999999 && $usage['invoice_count'] >= $usage['invoice_limit']) {
        respond([
            'error' => 'Quota de factures mensuel atteint (' . $usage['invoice_limit'] . '). Passez au plan Business pour continuer.',
            'usage' => $usage,
        ], 403);
    }

    $parsed = parseItems($body['items'] ?? [], $body['discount'] ?? 0);
    if (isset($parsed['error'])) {
        respond(['error' => $parsed['error']], 422);
    }

    $status = normalizeStatus($body['status'] ?? 'brouillon');
    $paymentMethod = trim((string) ($body['payment_method'] ?? ''));

    $pdo->beginTransaction();
    try {
        // La quantité facturée ne peut pas dépasser le stock restant
        // (validation atomique avec la déduction, dans la transaction)
        $stockErr = validateStockLines($pdo, $userId, $parsed['lines']);
        if ($stockErr) {
            $pdo->rollBack();
            respond(['error' => $stockErr['error']], 409);
        }

        $ref = nextInvoiceRef($pdo, $userId);
        $issuedAt = in_array($status, ['en_attente', 'payee'], true) ? date('Y-m-d H:i:s') : null;
        $paidAt = $status === 'payee' ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare(
            'INSERT INTO invoices (user_id, client_id, ref, amount, vat, discount, total, status, due_date, issued_at, paid_at, notes, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            $userId,
            $clientId,
            $ref,
            $parsed['amount'],
            $parsed['vat'],
            $parsed['discount'],
            $parsed['total'],
            $status,
            $dueDate,
            $issuedAt,
            $paidAt,
            $body['notes'] ?? null,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        insertItems($pdo, $invoiceId, $parsed['lines']);

        // Déduction automatique du stock dès que la facture est émise (non brouillon)
        if ($status !== 'brouillon' && $status !== 'annulee') {
            adjustStock($pdo, $userId, $parsed['lines'], -1);
            $pdo->prepare('UPDATE invoices SET stock_deducted = 1 WHERE id = ?')->execute([$invoiceId]);
        }

        if ($status === 'payee' && $paymentMethod !== '') {
            recordPayment($pdo, $userId, $invoiceId, $parsed['total'], $paymentMethod);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(['error' => 'Erreur lors de la création de la facture'], 500);
    }

    notify($pdo, $userId, 'Facture créée', 'La facture ' . $ref . ' d\'un montant de ' . number_format($parsed['total'], 2, ',', ' ') . ' € a été créée.', 'paiement');

    respond([
        'success' => true,
        'message' => 'Facture ' . $ref . ' créée',
        'invoice' => getInvoice($pdo, $userId, $invoiceId),
        'usage' => usageInfo($pdo, $userId),
    ], 201);
}

// ============================================================
// MODIFICATION
// ============================================================
if ($action === 'update') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $invoiceId = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    $current = getInvoice($pdo, $userId, $invoiceId);
    if (!$current) {
        respond(['error' => 'Facture introuvable'], 404);
    }
    if ($current['status'] === 'annulee') {
        respond(['error' => 'Une facture annulée ne peut pas être modifiée'], 422);
    }

    // Quota vérifié aussi en édition : si le quota est atteint, on n'autorise que
    // la modification d'une facture de ce mois qui compte déjà dans le quota.
    $usage = usageInfo($pdo, $userId);
    if ($usage['invoice_limit'] < 999999 && $usage['invoice_count'] >= $usage['invoice_limit']) {
        $createdThisMonth = (strtotime((string) $current['created_at']) ?: 0) >= strtotime(date('Y-m-01'));
        $countsTowardQuota = $createdThisMonth && !in_array($current['status'], ['brouillon', 'annulee'], true);
        if (!$countsTowardQuota) {
            respond([
                'error' => 'Quota de factures mensuel atteint (' . $usage['invoice_limit'] . '). Passez au plan Business pour continuer.',
                'usage' => $usage,
            ], 403);
        }
    }

    $clientId = findClient($pdo, $userId, $body['client_id'] ?? 0);
    if (isset($body['client_id']) && (int) $body['client_id'] !== 0 && $clientId === null) {
        respond(['error' => 'Client invalide'], 422);
    }

    $dueDate = trim((string) ($body['due_date'] ?? $current['due_date']));
    if ($dueDate === '' || strtotime($dueDate) === false) {
        respond(['error' => 'Date d\'échéance requise'], 422);
    }

    $parsed = isset($body['items']) ? parseItems($body['items'], $body['discount'] ?? 0) : null;
    if (is_array($parsed) && isset($parsed['error'])) {
        respond(['error' => $parsed['error']], 422);
    }

    $wasDeducted = (int) ($current['stock_deducted'] ?? 0) === 1;

    $status = normalizeStatus($body['status'] ?? $current['status']);
    $paymentMethod = trim((string) ($body['payment_method'] ?? ''));

    $amount = $parsed['amount'] ?? (float) $current['amount'];
    $vat = $parsed['vat'] ?? (float) $current['vat'];
    $discount = $parsed['discount'] ?? (float) ($current['discount'] ?? 0);
    // Clamp de symétrie : la remise ne peut jamais dépasser HT + TVA (même
    // quand les lignes ne sont pas renvoyées lors de la mise à jour).
    $discount = round(min(max(0, $discount), $amount + $vat), 2);
    $total = $parsed['total'] ?? round($amount + $vat - $discount, 2);

    $wasPaid = $current['status'] === 'payee';
    $paidAt = $wasPaid ? $current['paid_at'] : null;
    if ($status === 'payee' && !$wasPaid) {
        $paidAt = date('Y-m-d H:i:s');
    }

    $pdo->beginTransaction();
    try {
        // Remise de l'ancien stock uniquement si on remplace les lignes OU si la
        // facture repasse en brouillon/annulée (sinon rien ne change au stock).
        if ($wasDeducted && ($parsed !== null || $status === 'brouillon' || $status === 'annulee')) {
            adjustStock($pdo, $userId, $current['items'], 1);
        }

        // Validation après remise de l'ancien stock : les nouvelles quantités ne
        // peuvent pas dépasser le stock restant (atomique, dans la transaction).
        if ($parsed !== null) {
            $stockErr = validateStockLines($pdo, $userId, $parsed['lines']);
            if ($stockErr) {
                $pdo->rollBack();
                respond(['error' => $stockErr['error']], 409);
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE invoices SET client_id = ?, amount = ?, vat = ?, discount = ?, total = ?, status = ?, due_date = ?, issued_at = ?, paid_at = ?, notes = ?, updated_at = NOW() WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([
            $clientId,
            $amount,
            $vat,
            $discount,
            $total,
            $status,
            $dueDate,
            $current['issued_at'] ?: ($status === 'payee' || $status === 'en_attente' ? date('Y-m-d H:i:s') : null),
            $paidAt,
            array_key_exists('notes', $body) ? ($body['notes'] ?? null) : $current['notes'],
            $invoiceId,
            $userId,
        ]);

        if ($parsed !== null) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
            insertItems($pdo, $invoiceId, $parsed['lines']);
        }

        // Re-déduction avec les nouvelles quantités si la facture reste émise
        if ($parsed !== null) {
            if ($status !== 'brouillon' && $status !== 'annulee') {
                adjustStock($pdo, $userId, $parsed['lines'], -1);
                $pdo->prepare('UPDATE invoices SET stock_deducted = 1 WHERE id = ?')->execute([$invoiceId]);
            } else {
                $pdo->prepare('UPDATE invoices SET stock_deducted = 0 WHERE id = ?')->execute([$invoiceId]);
            }
        } elseif ($status === 'brouillon' || $status === 'annulee') {
            $pdo->prepare('UPDATE invoices SET stock_deducted = 0 WHERE id = ?')->execute([$invoiceId]);
        }
        // else : items non envoyés et facture émise => le stock_deducted reste inchangé

        // Paiement enregistré si marquée payée lors de cette mise à jour
        if ($status === 'payee' && !$wasPaid && $paymentMethod !== '') {
            recordPayment($pdo, $userId, $invoiceId, $total, $paymentMethod);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(['error' => 'Erreur lors de la mise à jour de la facture'], 500);
    }

    notify($pdo, $userId, 'Facture modifiée', 'La facture ' . $current['ref'] . ' a été mise à jour.', 'paiement');

    respond([
        'success' => true,
        'message' => 'Facture ' . $current['ref'] . ' mise à jour',
        'invoice' => getInvoice($pdo, $userId, $invoiceId),
    ]);
}

// ============================================================
// CHANGEMENT DE STATUT / MARQUAGE PAYÉ
// ============================================================
if ($action === 'status') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $invoiceId = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    $current = getInvoice($pdo, $userId, $invoiceId);
    if (!$current) {
        respond(['error' => 'Facture introuvable'], 404);
    }

    $newStatus = normalizeStatus($body['status'] ?? '');
    $paymentMethod = trim((string) ($body['payment_method'] ?? ''));

    // Gestion du stock lors d'un changement de statut (atomique) :
    //  - passage brouillon -> émis : on déduit le stock (avec validation)
    //  - passage émis -> brouillon/annulée : on remet le stock
    $wasDeducted = (int) ($current['stock_deducted'] ?? 0) === 1;
    $isEmis = !in_array($newStatus, ['brouillon', 'annulee'], true);

    $pdo->beginTransaction();
    try {
        if (!$wasDeducted && $isEmis) {
            // Validation avant déduction (le stock physique est la référence)
            $stockErr = validateStockLines($pdo, $userId, $current['items']);
            if ($stockErr) {
                $pdo->rollBack();
                respond(['error' => $stockErr['error']], 409);
            }
            adjustStock($pdo, $userId, $current['items'], -1);
            $pdo->prepare('UPDATE invoices SET stock_deducted = 1 WHERE id = ?')->execute([$invoiceId]);
        } elseif ($wasDeducted && !$isEmis) {
            // Remise du stock et marquage : la facture ne déduit plus
            adjustStock($pdo, $userId, $current['items'], 1);
            $pdo->prepare('UPDATE invoices SET stock_deducted = 0 WHERE id = ?')->execute([$invoiceId]);
        }

        if ($newStatus === 'payee') {
            $paidAt = $current['paid_at'] ?: date('Y-m-d H:i:s');
            if (!$current['paid_at'] && $paymentMethod !== '') {
                recordPayment($pdo, $userId, $invoiceId, (float) $current['total'], $paymentMethod);
            }
        } else {
            $paidAt = null;
        }

        $stmt = $pdo->prepare('UPDATE invoices SET status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$newStatus, $paidAt, $invoiceId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(['error' => 'Erreur lors du changement de statut'], 500);
    }

    if ($newStatus === 'payee') {
        notify($pdo, $userId, 'Paiement reçu', 'La facture ' . $current['ref'] . ' a été marquée comme payée.', 'paiement');
    } elseif ($newStatus === 'annulee') {
        notify($pdo, $userId, 'Facture annulée', 'La facture ' . $current['ref'] . ' a été annulée.', 'paiement');
    }

    respond([
        'success' => true,
        'message' => 'Statut mis à jour : ' . $newStatus,
        'invoice' => getInvoice($pdo, $userId, $invoiceId),
    ]);
}

// ============================================================
// SUPPRESSION
// ============================================================
if ($action === 'delete') {
    $body = readBody();
    if (!csrfValidateToken($body['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $invoiceId = (int) ($body['id'] ?? $_GET['id'] ?? 0);

    $current = getInvoice($pdo, $userId, $invoiceId);
    if (!$current) {
        respond(['error' => 'Facture introuvable'], 404);
    }

    // Remise du stock + suppression atomiques
    $pdo->beginTransaction();
    try {
        if ((int) ($current['stock_deducted'] ?? 0) === 1) {
            adjustStock($pdo, $userId, $current['items'], 1);
        }
        $stmt = $pdo->prepare('DELETE FROM invoices WHERE id = ? AND user_id = ?');
        $stmt->execute([$invoiceId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(['error' => 'Erreur lors de la suppression de la facture'], 500);
    }

    notify($pdo, $userId, 'Facture supprimée', 'La facture ' . $current['ref'] . ' a été supprimée.', 'paiement');

    respond(['success' => true, 'message' => 'Facture ' . $current['ref'] . ' supprimée']);
}

respond(['error' => 'Action inconnue'], 400);
