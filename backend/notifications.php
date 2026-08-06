<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$userId = $_SESSION['user_id'];

$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Marquer toutes les notifications comme lues
if ($action === 'mark_all_read') {
    require __DIR__ . '/csrf.php';
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
    echo json_encode(['success' => true]);
    exit;
}

// Marquer une notification comme lue
if ($action === 'mark_read') {
    require __DIR__ . '/csrf.php';
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

$stmt = $pdo->prepare('SELECT id, title, body, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

echo json_encode(['notifications' => $notifications]);
