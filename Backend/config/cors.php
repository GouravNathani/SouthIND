<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5174',
        'http://localhost:5175',
        'http://127.0.0.1:5175',
        'https://wd.bcexch9.com',
        'https://bcexch9.com',
    ],
    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\\.)*bcexch9\\.com$#i',
        '#^https?://bcexch9\\.com$#i',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
