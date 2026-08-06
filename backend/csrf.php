<?php
function csrfGenerateToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

function csrfValidateToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $stored = $_SESSION['csrf_token'] ?? '';
    $storedTime = $_SESSION['csrf_token_time'] ?? 0;

    if (empty($stored) || empty($token)) {
        return false;
    }

    // Validité du token étendue à 24 h pour éviter les 403 sur les pages
    // laissées ouvertes (le token reste lié à la session).
    if (time() - $storedTime > 86400) {
        return false;
    }

    return hash_equals($stored, $token);
}

function csrfHeader() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_SESSION['csrf_token'] ?? '';
    header('X-CSRF-Token: ' . $token);
    return $token;
}

// Endpoint HTTP : GET backend/csrf.php?action=token  =>  { "csrf_token": "..." }
// Utilisé par fetchCsrfToken() côté client (scripts/*.js).
// On réutilise le token de session existant s'il est encore valide, pour ne pas
// invalider les autres onglets ouverts par le même utilisateur.
if (($_GET['action'] ?? '') === 'token') {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_SESSION['csrf_token'] ?? '';
    $storedTime = $_SESSION['csrf_token_time'] ?? 0;
    if (empty($token) || (time() - $storedTime) > 86400) {
        $token = csrfGenerateToken();
    }
    echo json_encode(['csrf_token' => $token]);
    exit;
}