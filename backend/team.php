<?php
/**
 * backend/team.php — Gestion des membres de l'équipe (plan Business).
 *
 * Actions :
 *   GET  team.php?action=members          => liste des membres (propriétaire)
 *   POST team.php?action=add    {email}   => ajoute un membre (propriétaire Business)
 *   POST team.php?action=remove {member_id} => retire un membre (propriétaire)
 *
 * Un membre ajouté dispose d'un compte (créé automatiquement s'il n'existe pas)
 * et agit, une fois connecté, sur les données du propriétaire (planUserId).
 */
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
$userId = (int) $_SESSION['user_id'];

// Un membre ne peut pas gérer l'équipe (seul le propriétaire le peut)
$ownerId = planUserId($pdo, $userId);
if ($ownerId !== $userId) {
    http_response_code(403);
    echo json_encode(['error' => 'Seul le propriétaire de l\'équipe peut gérer les membres']);
    exit;
}

// Migration best-effort : table team_members
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

function respond($data, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function teamNotify(PDO $pdo, int $userId, string $title, string $body)
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    $stmt->execute([$userId, $title, $body, 'systeme']);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'members';

if ($action === 'members') {
    // Liste des membres actifs avec leurs infos
    $stmt = $pdo->prepare('
        SELECT tm.id, tm.member_id, tm.created_at, u.name, u.email, u.role
        FROM team_members tm
        JOIN users u ON u.id = tm.member_id
        WHERE tm.owner_id = ? AND tm.status = "active"
        ORDER BY tm.created_at ASC
    ');
    $stmt->execute([$ownerId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ownerStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $ownerStmt->execute([$ownerId]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

    respond([
        'success' => true,
        'plan' => planName($pdo, $ownerId),
        'owner' => $owner,
        'members' => $members,
    ]);
}

if ($action === 'add') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }

    // Seuls les comptes Business peuvent avoir des membres
    if (!isBusinessPlan(planName($pdo, $ownerId))) {
        respond(['error' => 'La gestion d\'équipe est réservée au plan Business'], 403);
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ''));
    // Mot de passe optionnel : si fourni, il est défini par le propriétaire
    // (au lieu d'un mot de passe temporaire généré aléatoirement).
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Adresse e-mail invalide'], 422);
    }
    if ($name === '') {
        $name = explode('@', $email)[0];
    }
    if ($email === strtolower(trim((string) ($_SESSION['user_email'] ?? '')))) {
        respond(['error' => 'Vous ne pouvez pas vous ajouter vous-même'], 422);
    }

    // Même politique que l'inscription : min 8 caractères et au moins un chiffre
    if ($password !== '' && (strlen($password) < 8 || !preg_match('/\d/', $password))) {
        respond(['error' => 'Le mot de passe doit contenir au moins 8 caractères et inclure au moins un chiffre.'], 422);
    }

    // Compte existant ?
    $stmt = $pdo->prepare('SELECT id, role, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $memberId = (int) $existing['id'];
        if ($memberId === $ownerId) {
            respond(['error' => 'Vous ne pouvez pas vous ajouter vous-même'], 422);
        }
    } else {
        // Créer un compte : mot de passe du propriétaire OU temporaire aléatoire
        if ($password !== '') {
            // Le propriétaire a défini le mot de passe : aucun temporaire à transmettre
            $tempPass = '';
        } else {
            // Politique : 8+ caractères et au moins un chiffre
            $tempPass = 'Biz' . substr(bin2hex(random_bytes(5)), 0, 8) . '1';
        }

        $codeStmt = $pdo->prepare('SELECT id FROM users WHERE business_code = ?');
        do {
            $candidate = 'FAC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $codeStmt->execute([$candidate]);
        } while ($codeStmt->fetchColumn());

        $hash = password_hash($password !== '' ? $password : $tempPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, business_code, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$name, $email, $hash, 'user', $candidate]);
        $memberId = (int) $pdo->lastInsertId();

        if ($password !== '') {
            teamNotify($pdo, $memberId, 'Bienvenue dans l\'équipe !', 'Vous avez été ajouté comme membre de l\'équipe. Votre mot de passe vous a été communiqué par le propriétaire.');
        } else {
            teamNotify($pdo, $memberId, 'Bienvenue dans l\'équipe !', 'Vous avez été ajouté comme membre de l\'équipe. Votre mot de passe temporaire : ' . $tempPass . ' — changez-le dans Mon profil.');
        }
    }

    // Le compte vient d'être créé : l'admin doit pouvoir transmettre le mot de passe
    $accountCreated = !$existing;

    // Déjà membre ?
    $dup = $pdo->prepare('SELECT id FROM team_members WHERE owner_id = ? AND member_id = ?');
    $dup->execute([$ownerId, $memberId]);
    if ($dup->fetchColumn()) {
        respond(['error' => 'Cette personne est déjà membre de votre équipe'], 409);
    }

    $stmt = $pdo->prepare('INSERT INTO team_members (owner_id, member_id, status, created_at) VALUES (?, ?, "active", NOW())');
    $stmt->execute([$ownerId, $memberId]);

    teamNotify($pdo, $memberId, 'Membre de l\'équipe', 'Vous gérez désormais les données de l\'entreprise avec le propriétaire. Connectez-vous pour accéder aux mêmes clients, produits, factures et devis.');

    $payload = [
        'success' => true,
        'message' => 'Membre ajouté : ' . $email,
        'member' => [
            'id' => (int) $pdo->lastInsertId(),
            'member_id' => $memberId,
            'name' => $existing ? $existing['name'] : $name,
            'email' => $email,
            'account_created' => $accountCreated,
            // Le mot de passe défini par le propriétaire n'est appliqué que lors de
            // la création du compte (jamais sur un compte existant).
            'password_set_by_owner' => $accountCreated && $password !== '',
            'password_ignored' => !$accountCreated && $password !== '',
        ],
    ];
    // Mot de passe temporaire renvoyé UNIQUEMENT si le compte a été créé ici avec
    // un mot de passe généré (jamais pour un compte existant ni si le propriétaire
    // a défini lui-même le mot de passe) pour permettre sa transmission au membre.
    if ($accountCreated && $password === '') {
        $payload['member']['temp_password'] = $tempPass;
    }
    respond($payload, 201);
}

if ($action === 'remove') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { respond(['error' => 'Token CSRF invalide'], 403); }
    $memberId = (int) ($_POST['member_id'] ?? 0);
    if (!$memberId) {
        respond(['error' => 'Membre invalide'], 422);
    }

    $stmt = $pdo->prepare('DELETE FROM team_members WHERE owner_id = ? AND member_id = ?');
    $stmt->execute([$ownerId, $memberId]);
    if ($stmt->rowCount() === 0) {
        respond(['error' => 'Ce membre ne fait pas partie de votre équipe'], 404);
    }

    teamNotify($pdo, $memberId, 'Membre retiré', 'Vous ne faites plus partie de l\'équipe. Vous n\'avez plus accès aux données de l\'entreprise.');

    respond(['success' => true, 'message' => 'Membre retiré de l\'équipe']);
}

respond(['error' => 'Action inconnue'], 400);
