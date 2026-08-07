<?php
/**
 * backend/plan.php — Quotas et logique de plan centralisés (Free / Business).
 *
 * Toutes les pages (factures, devis, produits, abonnement…) utilisent ces
 * fonctions pour rester cohérentes entre elles.
 *
 * Plans :
 *   - Free    : 3 factures/mois, 3 devis/mois, 10 produits au total (max en base)
 *   - Business: illimité (999999 = illimité côté code)
 */

if (!function_exists('planQuotas')) {
    function planQuotas($planName)
    {
        $name = strtolower(trim((string) $planName));
        if ($name === 'free') {
            return [
                'invoices_month' => 3,
                'quotes_month'   => 3,
                'products_total' => 10,
            ];
        }
        // Business (et tout plan payant) : illimité
        return [
            'invoices_month' => 999999,
            'quotes_month'   => 999999,
            'products_total' => 999999,
        ];
    }
}

if (!function_exists('planName')) {
    // Nom du plan actif de l'utilisateur (résolu vers l'entreprise en cas de membre)
    function planName(PDO $pdo, int $userId)
    {
        $stmt = $pdo->prepare('SELECT plan_name FROM subscriptions WHERE user_id = ? AND status = "active" ORDER BY renews_at DESC, id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $name = $stmt->fetchColumn();
        return $name ?: 'Free';
    }
}

if (!function_exists('isBusinessPlan')) {
    function isBusinessPlan($planName)
    {
        return strtolower(trim((string) $planName)) !== 'free';
    }
}

if (!function_exists('planEnsureSchema')) {
    // Migration best-effort : garantit l'existence de la table team_members
    // avant tout usage (planUserId, planUsage…). Exécutée au maximum une fois
    // par requête grâce au drapeau statique.
    function planEnsureSchema(PDO $pdo)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS team_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL,
                member_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_owner_member (owner_id, member_id),
                KEY idx_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } catch (Exception $e) {
            // ignore
        }
    }
}

if (!function_exists('planUserId')) {
    // Table team_members : un membre agit sur les données du propriétaire.
    // Pour un utilisateur non-membre, renvoie son propre id.
    $GLOBALS['__plan_user_cache'] = $GLOBALS['__plan_user_cache'] ?? [];
    function planUserId(PDO $pdo, int $userId)
    {
        planEnsureSchema($pdo);
        if (isset($GLOBALS['__plan_user_cache'][$userId])) {
            return $GLOBALS['__plan_user_cache'][$userId];
        }
        $stmt = $pdo->prepare('SELECT owner_id FROM team_members WHERE member_id = ? AND status = "active" LIMIT 1');
        $stmt->execute([$userId]);
        $ownerId = (int) $stmt->fetchColumn();
        $GLOBALS['__plan_user_cache'][$userId] = $ownerId > 0 ? $ownerId : $userId;
        return $GLOBALS['__plan_user_cache'][$userId];
    }
}

if (!function_exists('isTeamMember')) {
    function isTeamMember(PDO $pdo, int $userId): bool
    {
        return planUserId($pdo, $userId) !== $userId;
    }
}

if (!function_exists('planUsage')) {
    // Usage complet de l'utilisateur : compteurs + limites selon le plan.
    function planUsage(PDO $pdo, int $userId)
    {
        $ownerId = planUserId($pdo, $userId);
        $plan = planName($pdo, $ownerId);
        $q = planQuotas($plan);

        // Factures du mois (brouillons et annulées exclues)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE user_id = ? AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND status NOT IN ("brouillon", "annulee")');
        $stmt->execute([$ownerId]);
        $invoiceCount = (int) $stmt->fetchColumn();

        // Devis du mois (brouillons exclus)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM quotes WHERE user_id = ? AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01") AND LOWER(REPLACE(status, "é", "e")) NOT IN ("brouillon")');
        $stmt->execute([$ownerId]);
        $quoteCount = (int) $stmt->fetchColumn();

        // Produits au total (présents en base)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE user_id = ?');
        $stmt->execute([$ownerId]);
        $productCount = (int) $stmt->fetchColumn();

        return [
            'plan'              => $plan,
            'is_member'         => planUserId($pdo, $userId) !== $userId,
            'invoice_count'     => $invoiceCount,
            'invoice_limit'     => $q['invoices_month'],
            'quote_count'       => $quoteCount,
            'quote_limit'       => $q['quotes_month'],
            'product_count'     => $productCount,
            'product_limit'     => $q['products_total'],
            'quotas'            => $q,
        ];
    }
}
