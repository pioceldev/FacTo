<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';
header('Content-Type: application/json; charset=utf-8');

$isLoggedIn = !empty($_SESSION['user_id']);

echo json_encode([
    'logged_in' => $isLoggedIn,
    'user' => $isLoggedIn ? [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? null,
        'email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['user_role'] ?? 'user',
    ] : null,
]);
