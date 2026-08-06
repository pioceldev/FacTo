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

    if (time() - $storedTime > 3600) {
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