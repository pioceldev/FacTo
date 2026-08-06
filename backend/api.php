<?php
require __DIR__ . '/db.php';
require __DIR__ . '/tools.php';

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts = explode('/', $path);
$resource = end($parts);

switch ($resource) {
    case 'auth.php':
        require __DIR__ . '/auth.php';
        break;
    case 'support.php':
        require __DIR__ . '/support.php';
        break;
    case 'dashboard.php':
        require __DIR__ . '/dashboard.php';
        break;
    case 'invoices.php':
        require __DIR__ . '/invoices.php';
        break;
    case 'notifications.php':
        require __DIR__ . '/notifications.php';
        break;
    case 'subscription.php':
        require __DIR__ . '/subscription.php';
        break;
    case 'health.php':
        require __DIR__ . '/health.php';
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
        break;
}
