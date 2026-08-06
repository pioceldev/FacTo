<?php
function logError(string $message, array $context = []): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $entry = date('Y-m-d H:i:s') . ' [' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '] ' . $message;
    if (!empty($context)) {
        $entry .= ' | context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $entry .= PHP_EOL;

    $file = $logDir . '/errors.log';
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

function logAccess(string $message): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $entry = date('Y-m-d H:i:s') . ' [' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '] ' . $message . PHP_EOL;
    $file = $logDir . '/access.log';
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}