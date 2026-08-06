<?php
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');
$email = trim($_POST['email'] ?? '');

if (!$subject || !$message || !$email) {
    http_response_code(422);
    echo json_encode(['error' => 'Subject, message, and email are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare('INSERT INTO support_tickets (subject, message, email, created_at, status) VALUES (?, ?, ?, NOW(), ?);');
$stmt->execute([$subject, $message, $email, 'open']);

http_response_code(201);
echo json_encode(['success' => true, 'message' => 'Support ticket created']);
