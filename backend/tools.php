<?php
function safeJson($data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireSession()
{
    require __DIR__ . '/init.php';
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        safeJson(['error' => 'Non autorisé']);
    }
}

function requireAdmin()
{
    requireSession();
    if (($_SESSION['user_role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        safeJson(['error' => 'Accès réservé aux administrateurs']);
    }
}
