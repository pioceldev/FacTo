<?php
$configFile = __DIR__ . '/../backend/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../backend/config.example.php';
}
$config = require $configFile;

foreach ($config as $key => $value) {
    if (!getenv($key)) {
        putenv("$key=" . (is_array($value) ? json_encode($value) : $value));
    }
}

require __DIR__ . '/../backend/db.php';
require __DIR__ . '/../backend/init.php';