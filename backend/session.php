<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';
header('Content-Type: application/json; charset=utf-8');

$isLoggedIn = !empty($_SESSION['user_id']);
$currency = 'EUR';

if ($isLoggedIn) {
    try {
        $pdo = getPDO();
        // Best-effort : la colonne currency peut ne pas exister sur une base ancienne
        $q = $pdo->prepare('SELECT currency FROM users WHERE id = ?');
        $q->execute([(int) $_SESSION['user_id']]);
        $currency = (string) ($q->fetchColumn() ?: 'EUR');
    } catch (Exception $e) {
        $currency = 'EUR';
    }
}

echo json_encode([
    'logged_in' => $isLoggedIn,
    'currency' => $currency,
    'user' => $isLoggedIn ? [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? null,
        'email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['user_role'] ?? 'user',
    ] : null,
]);
