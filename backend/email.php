<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? 'send';

function sanitizeEmail($email) {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function buildEmailHeaders($fromName, $fromEmail, $replyTo = null) {
    $headers = "From: " . mb_encode_mimeheader($fromName, 'UTF-8') . " <" . $fromEmail . ">\r\n";
    $headers .= "Reply-To: " . $fromEmail . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    if ($replyTo) {
        $headers .= "Reply-To: " . $replyTo . "\r\n";
    }
    return $headers;
}

function sendEmail($to, $subject, $htmlBody, $textBody = '') {
    global $config;

    $to = sanitizeEmail($to);
    if (!validateEmail($to)) {
        return ['success' => false, 'error' => 'Adresse email invalide'];
    }

    $fromName = $config['app_name'] ?? 'FacTo';
    $fromEmail = $config['app_email'] ?? 'noreply@facto.local';

    $headers = buildEmailHeaders($fromName, $fromEmail);

    if (!empty($textBody)) {
        $headers .= "MIME-Version: 1.0\r\n";
        $boundary = '----FacToBoundary' . md5(uniqid());
        $headers .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";

        $message = "--" . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $textBody . "\r\n\r\n";
        $message .= "--" . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= "--" . $boundary . "--\r\n";
    } else {
        $message = $htmlBody;
    }

    $subject = mb_encode_mimeheader($subject, 'UTF-8');

    $debug = $config['app_debug'] ?? false;
    if ($debug) {
        return [
            'success' => true,
            'debug' => true,
            'to' => $to,
            'subject' => $subject,
            'headers' => $headers,
            'message_preview' => substr($message, 0, 500),
        ];
    }

    $sent = mail($to, $subject, $message, $headers);
    return ['success' => $sent, 'sent' => $sent];
}

try {
    switch ($action) {
        case 'send':
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $to = $body['to'] ?? '';
            $subject = $body['subject'] ?? '';
            $htmlBody = $body['html'] ?? '';
            $textBody = $body['text'] ?? '';

            if (empty($to) || empty($subject)) {
                http_response_code(400);
                echo json_encode(['error' => 'to et subject requis']);
                exit;
            }

            $result = sendEmail($to, $subject, $htmlBody, $textBody);
            echo json_encode($result);
            break;

        case 'invoice_notification':
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $invoiceId = intval($body['invoice_id'] ?? 0);
            $clientEmail = sanitizeEmail($body['client_email'] ?? '');

            if ($invoiceId <= 0 || !validateEmail($clientEmail)) {
                http_response_code(400);
                echo json_encode(['error' => 'invoice_id et client_email valides requis']);
                exit;
            }

            $pdo = getPDO();
            $userId = $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT i.*, c.name AS client_name FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = :id AND i.user_id = :uid");
            $stmt->execute([':id' => $invoiceId, ':uid' => $userId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                http_response_code(404);
                echo json_encode(['error' => 'Facture introuvable']);
                exit;
            }

            $html = '<h2>Facture ' . htmlspecialchars($invoice['ref']) . '</h2>';
            $html .= '<p>Bonjour ' . htmlspecialchars($invoice['client_name']) . ',</p>';
            $html .= '<p>Votre facture d\'un montant de ' . number_format($invoice['total'], 0, ',', ' ') . ' FCFA est disponible.</p>';
            $html .= '<p>Statut : <strong>' . htmlspecialchars($invoice['status']) . '</strong></p>';
            $html .= '<p>Cordialement,<br>L\'équipe FacTo</p>';

            $text = "Facture " . $invoice['ref'] . "\n";
            $text .= "Bonjour " . $invoice['client_name'] . ",\n";
            $text .= "Votre facture d'un montant de " . number_format($invoice['total'], 0, ',', ' ') . " FCFA est disponible.\n";
            $text .= "Statut: " . $invoice['status'] . "\n";

            $result = sendEmail($clientEmail, 'Facture ' . $invoice['ref'], $html, $text);
            echo json_encode($result);
            break;

        case 'quote_notification':
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $quoteId = intval($body['quote_id'] ?? 0);
            $clientEmail = sanitizeEmail($body['client_email'] ?? '');

            if ($quoteId <= 0 || !validateEmail($clientEmail)) {
                http_response_code(400);
                echo json_encode(['error' => 'quote_id et client_email valides requis']);
                exit;
            }

            $pdo = getPDO();
            $userId = $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT q.*, c.name AS client_name FROM quotes q LEFT JOIN clients c ON q.client_id = c.id WHERE q.id = :id AND q.user_id = :uid");
            $stmt->execute([':id' => $quoteId, ':uid' => $userId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quote) {
                http_response_code(404);
                echo json_encode(['error' => 'Devis introuvable']);
                exit;
            }

            $html = '<h2>Devis ' . htmlspecialchars($quote['ref']) . '</h2>';
            $html .= '<p>Bonjour ' . htmlspecialchars($quote['client_name']) . ',</p>';
            $html .= '<p>Votre devis d\'un montant de ' . number_format($quote['total'], 0, ',', ' ') . ' FCFA est disponible.</p>';
            $html .= '<p>Statut : <strong>' . htmlspecialchars($quote['status']) . '</strong></p>';
            $html .= '<p>Cordialement,<br>L\'équipe FacTo</p>';

            $text = "Devis " . $quote['ref'] . "\n";
            $text .= "Bonjour " . $quote['client_name'] . ",\n";
            $text .= "Votre devis d'un montant de " . number_format($quote['total'], 0, ',', ' ') . " FCFA est disponible.\n";
            $text .= "Statut: " . $quote['status'] . "\n";

            $result = sendEmail($clientEmail, 'Devis ' . $quote['ref'], $html, $text);
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur envoi email', 'detail' => $e->getMessage()]);
}