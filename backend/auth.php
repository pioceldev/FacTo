<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if (!$action) {
    http_response_code(400);
    echo json_encode(['error' => "Paramètre 'action' requis"]);
    exit;
}

$pdo = getPDO();

function respond($data, int $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($action === 'register') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $plan = trim($_POST['plan'] ?? 'free');

    if (!$email || !$password || !$name) {
        respond(['error' => "Le nom, l'adresse e-mail et le mot de passe sont requis"], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => "L'adresse e-mail n'est pas valide"], 422);
    }

    // Password policy: min 8 chars, at least one digit
    if (strlen($password) < 8 || !preg_match('/\d/', $password)) {
        respond(['error' => 'Le mot de passe doit contenir au moins 8 caractères et inclure au moins un chiffre.'], 422);
    }

    if ($phone && !preg_match('/^[0-9+\s.-]{6,20}$/', $phone)) {
        respond(['error' => 'Le numéro de téléphone n\'est pas valide'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        respond(['error' => 'E-mail déjà enregistré'], 409);
    }

    // Premier compte => admin, sinon rôle selon la liste configurée
    $countStmt = $pdo->query('SELECT COUNT(*) FROM users');
    $role = (int) $countStmt->fetchColumn() === 0 || in_array($email, (array) ($config['admin_emails'] ?? []), true)
        ? 'admin'
        : 'user';

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, company, phone, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$name, $email, $passwordHash, $company, $phone, $role]);
    $userId = $pdo->lastInsertId();

    if ($userId && $plan) {
        try {
            // Lier le plan Free/Standard/Premium s'il existe en base
            $planStmt = $pdo->prepare('SELECT id, price_monthly FROM plans WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1');
            $planStmt->execute([$plan]);
            $planRow = $planStmt->fetch();

            $nextBilling = date('Y-m-d', strtotime('+1 month'));
            $stmt = $pdo->prepare('INSERT INTO subscriptions (user_id, plan_id, plan_name, price, status, next_billing_date, renews_at, started_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([
                $userId,
                $planRow['id'] ?? null,
                $planRow['name'] ?? $plan,
                $planRow['price_monthly'] ?? null,
                'active',
                $nextBilling,
                $nextBilling,
            ]);
        } catch (Exception $e) {
            // If the subscriptions table is not available, ignore and continue registration.
        }
    }

    // Notification de bienvenue
    try {
        $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
        $stmt->execute([$userId, 'Bienvenue sur ' . ($config['app_name'] ?? 'FacTo'), 'Votre compte a été créé avec succès. Bonne gestion !', 'systeme']);
    } catch (Exception $e) {
        // ignore
    }

    respond(['success' => true, 'message' => "Utilisateur enregistré avec succès"]);
}

if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        respond(['error' => "L'e-mail et le mot de passe sont requis"], 422);
    }

    $stmt = $pdo->prepare('SELECT id, name, email, role, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(['error' => 'Identifiants invalides'], 401);
    }

    // Regenerate session id to prevent fixation
    if (function_exists('session_regenerate_id')) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];

    respond(['success' => true, 'user' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ]]);
}

if ($action === 'logout') {
    // destroy session server-side
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    respond(['success' => true, 'message' => 'Déconnecté']);
}

if ($action === 'forgot_password') {
    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Veuillez saisir une adresse e-mail valide'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Toujours répondre de la même façon (pas de révélation d'existence de compte)
    if ($user) {
        // Purge des anciens tokens de l'utilisateur
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['id']]);

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 7200); // 2h

        $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$user['id'], $tokenHash, $expiresAt]);

        $appName = $config['app_name'] ?? 'FacTo';
        $appUrl = rtrim($config['app_url'] ?? 'http://localhost/FacTo', '/');
        $resetUrl = $appUrl . '/reinitialiser-mot-de-passe.html?token=' . $token;

        $subject = '[' . $appName . '] Réinitialisation de votre mot de passe';
        $body = "Bonjour,\n\nVous avez demandé la réinitialisation de votre mot de passe.\nCliquez sur ce lien (valable 2 heures) :\n$resetUrl\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.\n\nL'équipe $appName";
        $sent = false;

        // Tentative d'envoi réel, silencieuse en échec (pas de mailer en local)
        if (!empty($config['app_debug']) || !@mail($email, $subject, $body)) {
            $sent = false;
        } else {
            $sent = true;
        }

        $response = ['success' => true, 'message' => 'Si un compte existe pour cette adresse, un lien de réinitialisation a été envoyé.'];
        if (!empty($config['app_debug'])) {
            $response['reset_link'] = $resetUrl;
        }
        respond($response);
    }

    respond(['success' => true, 'message' => 'Si un compte existe pour cette adresse, un lien de réinitialisation a été envoyé.']);
}

if ($action === 'reset_password') {
    $token = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$token) {
        respond(['error' => 'Lien de réinitialisation invalide'], 422);
    }

    if (strlen($password) < 8 || !preg_match('/\d/', $password)) {
        respond(['error' => 'Le mot de passe doit contenir au moins 8 caractères et inclure au moins un chiffre.'], 422);
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT id, user_id, expires_at FROM password_reset_tokens WHERE token_hash = ?');
    $stmt->execute([$tokenHash]);
    $record = $stmt->fetch();

    if (!$record) {
        respond(['error' => 'Lien de réinitialisation invalide ou déjà utilisé'], 422);
    }

    if (strtotime($record['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE id = ?')->execute([$record['id']]);
        respond(['error' => 'Ce lien a expiré. Veuillez refaire une demande.'], 422);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$passwordHash, $record['user_id']]);

    // Supprime le token (usage unique)
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE id = ?')->execute([$record['id']]);

    respond(['success' => true, 'message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.']);
}

respond(['error' => 'Unknown action'], 400);
