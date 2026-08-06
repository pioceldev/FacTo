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

// Anti-spam : max 5 tickets par heure et par adresse e-mail.
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$countStmt->execute([$email]);
if ((int) $countStmt->fetchColumn() >= 5) {
    http_response_code(429);
    echo json_encode(['error' => 'Trop de demandes envoyées depuis cette adresse. Réessayez plus tard.']);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO support_tickets (subject, message, email, created_at, status) VALUES (?, ?, ?, NOW(), ?);');
$stmt->execute([$subject, $message, $email, 'open']);

http_response_code(201);
echo json_encode(['success' => true, 'message' => 'Support ticket created']);
