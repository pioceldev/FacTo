<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';
require __DIR__ . '/fedapay.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/plan.php';

$config = $config ?? require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$userId = $_SESSION['user_id'];

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

function respond($data, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getCurrentSubscription(PDO $pdo, int $userId)
{
    $stmt = $pdo->prepare('SELECT id, plan_id, plan_name, price, payment_method, status, next_billing_date, renews_at, started_at, cancelled_at, discount_next_pct FROM subscriptions WHERE user_id = ? ORDER BY renews_at DESC, id DESC LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function notify(PDO $pdo, int $userId, string $title, string $body)
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    $stmt->execute([$userId, $title, $body, 'abonnement']);
}

// Active (ou change) l'abonnement après paiement validé.
// $effectivePrice : prix effectivement facturé (après réduction parrain éventuelle).
function activateSubscription(PDO $pdo, int $userId, int $planId, string $cycle, string $paymentMethod, ?float $effectivePrice = null)
{
    $stmt = $pdo->prepare('SELECT id, name, price_monthly, price_yearly FROM plans WHERE id = ? AND is_active = 1');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        return ['error' => 'Plan introuvable'];
    }

    $price = $effectivePrice !== null ? $effectivePrice : ($cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly']);
    $nextBilling = $cycle === 'yearly' ? date('Y-m-d', strtotime('+1 year')) : date('Y-m-d', strtotime('+1 month'));

    $currentStmt = $pdo->prepare('SELECT id FROM subscriptions WHERE user_id = ? ORDER BY renews_at DESC, id DESC LIMIT 1');
    $currentStmt->execute([$userId]);
    $current = $currentStmt->fetch();

    if ($current) {
        $stmt = $pdo->prepare('UPDATE subscriptions SET plan_id = ?, plan_name = ?, price = ?, payment_method = ?, status = ?, next_billing_date = ?, renews_at = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$plan['id'], $plan['name'], $price, $paymentMethod, 'active', $nextBilling, $nextBilling, $current['id']]);
        $subscriptionId = $current['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO subscriptions (user_id, plan_id, plan_name, price, payment_method, status, next_billing_date, renews_at, started_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$userId, $plan['id'], $plan['name'], $price, $paymentMethod, 'active', $nextBilling, $nextBilling]);
        $subscriptionId = $pdo->lastInsertId();
    }

    notify($pdo, $userId, 'Abonnement ' . $plan['name'], 'Votre abonnement ' . $plan['name'] . ' (' . ($cycle === 'yearly' ? 'annuel' : 'mensuel') . ') est actif. Prochaine facturation : ' . $nextBilling . '.');

    return ['plan' => $plan, 'price' => $price, 'cycle' => $cycle, 'subscription_id' => $subscriptionId];
}

// Crédite un parrain : 10 % de réduction sur son PROCHAIN renouvellement.
// Retourne true si le parrainage a été pris en compte, false sinon.
function grantReferralBonus(PDO $pdo, int $newUserId, string $businessCode): bool
{
    $code = strtoupper(trim($businessCode));
    if ($code === '') {
        return false;
    }

    // Le code doit appartenir à un AUTRE utilisateur
    $stmt = $pdo->prepare('SELECT id FROM users WHERE business_code = ?');
    $stmt->execute([$code]);
    $referrerId = (int) $stmt->fetchColumn();
    if (!$referrerId || $referrerId === $newUserId) {
        return false;
    }

    // Un seul parrainage par filleul (pas de multi-crédit)
    $dupStmt = $pdo->prepare('SELECT id FROM referrals WHERE referred_user_id = ?');
    $dupStmt->execute([$newUserId]);
    if ($dupStmt->fetchColumn()) {
        return false;
    }

    $pdo->prepare('INSERT INTO referrals (referrer_user_id, referred_user_id, code_used, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$referrerId, $newUserId, $code]);

    // +10 % de réduction sur le prochain renouvellement du parrain (cumulable, plafonné à 100 %)
    $subStmt = $pdo->prepare('SELECT id FROM subscriptions WHERE user_id = ? ORDER BY renews_at DESC, id DESC LIMIT 1');
    $subStmt->execute([$referrerId]);
    $subId = $subStmt->fetchColumn();
    if ($subId) {
        $pdo->prepare('UPDATE subscriptions SET discount_next_pct = LEAST(100, COALESCE(discount_next_pct, 0) + 10) WHERE id = ?')->execute([$subId]);
    } else {
        // Cas limite : le parrain n'a aucune ligne d'abonnement (rare), on la crée en Free
        $nextBilling = date('Y-m-d', strtotime('+1 month'));
        $pdo->prepare('INSERT INTO subscriptions (user_id, plan_name, price, status, next_billing_date, renews_at, discount_next_pct, started_at, created_at) VALUES (?, ?, 0.00, ?, ?, ?, 10, NOW(), NOW())')
            ->execute([$referrerId, 'Free', 'active', $nextBilling, $nextBilling]);
    }

    notify($pdo, $referrerId, '🎉 Parrainage réussi !', 'Un filleul s\'est abonné avec votre code business ' . $code . '. Vous bénéficiez de 10 % de réduction sur votre prochain renouvellement.');
    return true;
}

function getPaymentStatusLabel($status)
{
    $map = [
        'approved' => 'paid',
        'pending' => 'pending',
        'declined' => 'failed',
        'canceled' => 'canceled',
        'refunded' => 'refunded',
        'transferred' => 'paid',
    ];
    return $map[strtolower((string) $status)] ?? 'failed';
}

if ($action === 'get' || $action === 'status') {
    // Un membre d'équipe n'a pas sa propre ligne d'abonnement : on lui affiche
    // celle de l'entreprise (propriétaire) en lecture seule.
    $isMember = planUserId($pdo, $userId) !== $userId;
    $subscription = getCurrentSubscription($pdo, $isMember ? planUserId($pdo, $userId) : $userId);

    $plansStmt = $pdo->prepare('SELECT id, name, price_monthly, price_yearly, description, features FROM plans WHERE is_active = 1 ORDER BY sort_order');
    $plansStmt->execute();
    $plans = $plansStmt->fetchAll();

    $histStmt = $pdo->prepare('SELECT id, amount, payment_method, status, transaction_ref, paid_at, created_at FROM payment_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $histStmt->execute([$userId]);
    $history = $histStmt->fetchAll();

    // Usage complet (factures, devis, produits) centralisé dans plan.php
    $usage = planUsage($pdo, $userId);

    // Code business de l'utilisateur (affiché sur le profil / parrainage)
    $codeStmt = $pdo->prepare('SELECT business_code FROM users WHERE id = ?');
    $codeStmt->execute([$userId]);
    $businessCode = $codeStmt->fetchColumn();

    respond([
        'subscription' => $subscription,
        'plans' => $plans,
        'business_code' => $businessCode,
        'payment_history' => $history,
        'usage' => $usage,
        'is_member' => $isMember,
    ]);
}

if ($action === 'plans') {
    $stmt = $pdo->prepare('SELECT id, name, price_monthly, price_yearly, description, features FROM plans WHERE is_active = 1 ORDER BY sort_order');
    $stmt->execute();
    respond(['plans' => $stmt->fetchAll()]);
}

if ($action === 'subscribe') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    // Un membre d'équipe ne peut pas changer l'abonnement de l'entreprise
    if (planUserId($pdo, $userId) !== $userId) {
        respond(['error' => 'Seul le propriétaire de l\'entreprise peut gérer l\'abonnement'], 403);
    }
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $cycle = ($_POST['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $paymentMethod = trim($_POST['payment_method'] ?? 'carte');
    $referralCode = strtoupper(trim($_POST['referral_code'] ?? ''));

    if (!$planId) {
        respond(['error' => 'Plan invalide'], 422);
    }

    $stmt = $pdo->prepare('SELECT id, name, price_monthly, price_yearly FROM plans WHERE id = ? AND is_active = 1');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        respond(['error' => 'Plan introuvable'], 404);
    }

    $price = $cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
    $isPaidPlan = $price > 0;

    // Le code business saisi doit appartenir à un AUTRE utilisateur pour être valide
    $referralValid = false;
    if ($referralCode !== '') {
        $refStmt = $pdo->prepare('SELECT id FROM users WHERE business_code = ?');
        $refStmt->execute([$referralCode]);
        $refId = (int) $refStmt->fetchColumn();
        $referralValid = $refId > 0 && $refId !== $userId;
    }

    // Réduction de parrainage : 10 % (ou plus) sur le prix si l'utilisateur en a gagné
    // pour son prochain renouvellement. Le crédit est consommé au moment du paiement.
    $discountPct = 0.0;
    $currentSub = getCurrentSubscription($pdo, $userId);
    if ($currentSub) {
        $discountPct = (float) ($currentSub['discount_next_pct'] ?? 0);
    }

    // Bonus filleul : -5 % immédiats si un code business valide est saisi
    // (cumulable avec le crédit de parrainage de l'utilisateur).
    $totalDiscountPct = $discountPct + ($referralValid ? 5.0 : 0.0);

    $effectivePrice = $price;
    if ($isPaidPlan && $totalDiscountPct > 0) {
        $effectivePrice = round($price * (1 - min(100, $totalDiscountPct) / 100), 2);
    }

    // Plan gratuit : activation immédiate (pas de crédit de parrainage :
    // le parrain n'est récompensé que lorsqu'un filleul souscrit un plan PAYANT).
    if (!$isPaidPlan) {
        $result = activateSubscription($pdo, $userId, $plan['id'], $cycle, $paymentMethod, $effectivePrice);
        if (isset($result['error'])) {
            respond(['error' => $result['error']], 422);
        }

        respond([
            'success' => true,
            'message' => 'Abonnement ' . $plan['name'] . ' activé',
            'mode' => 'direct',
            'subscription' => getCurrentSubscription($pdo, $userId),
        ]);
    }

    // Plan payant : passage par FedaPay (page de paiement sécurisée)
    if (fedapayEnabled()) {
        $meta = json_encode([
            'plan_id' => $plan['id'],
            'cycle' => $cycle,
            'referral_code' => $referralCode,
            'discount_pct' => $discountPct > 0 ? $discountPct : null,
        ]);
        $stmt = $pdo->prepare('INSERT INTO payment_history (user_id, amount, payment_method, status, meta, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$userId, $effectivePrice, $paymentMethod, 'pending', $meta]);
        $historyId = $pdo->lastInsertId();

        $customerStmt = $pdo->prepare('SELECT name, email, phone FROM users WHERE id = ?');
        $customerStmt->execute([$userId]);
        $u = $customerStmt->fetch();

        $customer = ['email' => $u['email'] ?? ''];
        $nameParts = preg_split('/\s+/', trim($u['name'] ?? ''), 2);
        $customer['firstname'] = $nameParts[0] ?? '';
        $customer['lastname'] = $nameParts[1] ?? '';
        if (!empty($u['phone'])) {
            $digits = preg_replace('/\D+/', '', $u['phone']);
            if (strlen($digits) >= 8) {
                $customer['phone_number'] = [
                    'number' => (int) substr($digits, -9),
                    'country' => 'bj',
                ];
            }
        }

        $appUrl = rtrim($config['app_url'] ?? 'http://localhost/FacTo', '/');
        $callbackUrl = $appUrl . '/paiement-return.html';

        $res = fedapayCreateTransaction(
            fedapayAmount($effectivePrice),
            'Abonnement ' . $plan['name'] . ' (' . ($cycle === 'yearly' ? 'annuel' : 'mensuel') . ') - ' . ($config['app_name'] ?? 'FacTo'),
            $callbackUrl,
            $customer
        );

        $txId = (int) ($res['data']['transaction']['id'] ?? $res['data']['id'] ?? 0);

        if (($res['code'] ?? 0) >= 400 || !$txId) {
            $pdo->prepare('UPDATE payment_history SET status = ? WHERE id = ?')->execute(['failed', $historyId]);
            $detail = isset($res['data']['message']) ? $res['data']['message'] : 'Transaction refusée par FedaPay';
            respond(['error' => $detail], 502);
        }

        $pdo->prepare('UPDATE payment_history SET transaction_ref = ? WHERE id = ?')->execute([$txId, $historyId]);

        $paymentUrl = fedapayGeneratePaymentUrl($txId);
        if (!$paymentUrl) {
            $pdo->prepare('UPDATE payment_history SET status = ? WHERE id = ?')->execute(['failed', $historyId]);
            respond(['error' => 'Impossible de générer la page de paiement'], 502);
        }

        respond([
            'success' => true,
            'message' => 'Redirection vers FedaPay…',
            'mode' => 'fedapay',
            'payment_url' => $paymentUrl,
            'payment' => [
                'transaction_ref' => $txId,
                'amount' => $effectivePrice,
                'currency' => fedapayConfig()['currency'] ?? 'XOF',
                'status' => 'pending',
            ],
        ]);
    }

    // FedaPay non configuré : paiement simulé (mode local)
    $result = activateSubscription($pdo, $userId, $plan['id'], $cycle, $paymentMethod, $effectivePrice);
    if (isset($result['error'])) {
        respond(['error' => $result['error']], 422);
    }

    // Le crédit de parrainage est consommé par ce paiement
    if ($discountPct > 0) {
        $pdo->prepare('UPDATE subscriptions SET discount_next_pct = 0 WHERE user_id = ? ORDER BY renews_at DESC LIMIT 1')->execute([$userId]);
    }

    // Code business saisi : créditer le parrain (uniquement si le filleul paie)
    if ($referralCode !== '') {
        grantReferralBonus($pdo, $userId, $referralCode);
    }

    $txRef = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare('INSERT INTO payment_history (user_id, subscription_id, amount, payment_method, status, transaction_ref, paid_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$userId, $result['subscription_id'], $effectivePrice, $paymentMethod, 'paid', $txRef]);

    respond([
        'success' => true,
        'message' => 'Abonnement ' . $plan['name'] . ' activé',
        'mode' => 'simulated',
        'subscription' => getCurrentSubscription($pdo, $userId),
        'payment' => [
            'transaction_ref' => $txRef,
            'amount' => $effectivePrice,
            'status' => 'paid',
        ],
    ]);
}

if ($action === 'confirm') {
    // Appelé au retour de la page FedaPay (paiement-return.html)
    $txId = (int) ($_GET['id'] ?? 0);

    if (!$txId) {
        respond(['error' => 'Identifiant de transaction manquant'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM payment_history WHERE transaction_ref = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(string) $txId, $userId]);
    $history = $stmt->fetch();

    if (!$history) {
        respond(['error' => 'Transaction introuvable'], 404);
    }

    // Toujours vérifier le statut réel auprès de l'API (jamais celui de l'URL)
    $res = fedapayGetTransaction($txId);
    $tx = $res['data']['transaction'] ?? $res['data'] ?? [];
    $fedaStatus = strtolower((string) ($tx['status'] ?? ''));
    $internalStatus = getPaymentStatusLabel($fedaStatus);

    $pdo->prepare('UPDATE payment_history SET status = ? WHERE id = ?')->execute([$internalStatus, $history['id']]);

    if ($fedaStatus === 'approved') {
        $meta = json_decode($history['meta'] ?? '{}', true) ?: [];
        $result = activateSubscription(
            $pdo,
            $userId,
            (int) ($meta['plan_id'] ?? 0),
            $meta['cycle'] ?? 'monthly',
            $history['payment_method'] ?? 'carte',
            (float) $history['amount']
        );

        if (isset($result['error'])) {
            respond(['error' => $result['error']], 422);
        }

        // Crédit de parrainage consommé par ce paiement
        if (!empty($meta['discount_pct'])) {
            $pdo->prepare('UPDATE subscriptions SET discount_next_pct = 0 WHERE user_id = ? ORDER BY renews_at DESC LIMIT 1')->execute([$userId]);
        }

        // Code business du filleul : créditer le parrain après paiement confirmé
        $referralCode = strtoupper(trim((string) ($meta['referral_code'] ?? '')));
        if ($referralCode !== '') {
            grantReferralBonus($pdo, $userId, $referralCode);
        }

        $pdo->prepare('UPDATE payment_history SET subscription_id = ?, paid_at = NOW() WHERE id = ?')->execute([$result['subscription_id'], $history['id']]);

        respond([
            'success' => true,
            'message' => 'Paiement confirmé. Abonnement ' . $result['plan']['name'] . ' activé.',
            'status' => 'approved',
            'subscription' => getCurrentSubscription($pdo, $userId),
        ]);
    }

    notify($pdo, $userId, 'Paiement refusé', 'Le paiement FedaPay n\'a pas abouti (statut : ' . $fedaStatus . '). Aucun changement d\'abonnement n\'a été effectué.');

    respond([
        'success' => false,
        'message' => 'Paiement non abouti (statut : ' . $fedaStatus . ').',
        'status' => $fedaStatus,
    ], 402);
}

if ($action === 'cancel') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    // Un membre d'équipe ne peut pas résilier l'abonnement de l'entreprise
    if (planUserId($pdo, $userId) !== $userId) {
        respond(['error' => 'Seul le propriétaire de l\'entreprise peut gérer l\'abonnement'], 403);
    }
    $current = getCurrentSubscription($pdo, $userId);
    if (!$current) {
        respond(['error' => 'Aucun abonnement actif'], 422);
    }

    $stmt = $pdo->prepare('UPDATE subscriptions SET status = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ?');
    $stmt->execute(['cancelled', $current['id']]);

    notify($pdo, $userId, 'Abonnement résilié', 'Votre abonnement a été résilié. Vous conservez l\'accès jusqu\'au ' . ($current['next_billing_date'] ?? 'à la fin de la période') . '.');

    respond(['success' => true, 'message' => 'Abonnement résilié', 'subscription' => getCurrentSubscription($pdo, $userId)]);
}

if ($action === 'update_payment') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    if (!$paymentMethod) {
        respond(['error' => 'Méthode de paiement requise'], 422);
    }

    $current = getCurrentSubscription($pdo, $userId);
    if ($current) {
        $pdo->prepare('UPDATE subscriptions SET payment_method = ?, updated_at = NOW() WHERE id = ?')->execute([$paymentMethod, $current['id']]);
    }

    respond(['success' => true, 'message' => 'Méthode de paiement mise à jour']);
}

respond(['error' => 'Action inconnue'], 400);
