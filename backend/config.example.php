<?php
return [
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_name' => getenv('DB_NAME') ?: 'facto',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_pass' => getenv('DB_PASS') ?: '',
    'db_charset' => getenv('DB_CHARSET') ?: 'utf8mb4',

    'admin_emails' => explode(',', getenv('ADMIN_EMAILS') ?: ''),

    'app_debug' => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'app_name' => getenv('APP_NAME') ?: 'FacTo',
    'app_url' => getenv('APP_URL') ?: 'http://localhost/FacTo',

    'fedapay' => [
        'enabled' => filter_var(getenv('FEDAPAY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'secret_key' => getenv('FEDAPAY_SECRET_KEY') ?: '',
        'public_key' => getenv('FEDAPAY_PUBLIC_KEY') ?: '',
        'environment' => getenv('FEDAPAY_ENVIRONMENT') ?: 'sandbox',
        'currency' => getenv('FEDAPAY_CURRENCY') ?: 'XOF',
        'xof_per_eur' => (float) (getenv('FEDAPAY_XOF_PER_EUR') ?: 655.957),
    ],
];