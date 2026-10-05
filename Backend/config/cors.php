<?php

/*
 * Allowed browser origins come from CORS_ALLOWED_ORIGINS (comma-separated,
 * exact origins such as https://admin.example.com). The local dev SPAs are the
 * fallback when it is unset. Production sets the three SPA origins in the
 * server .env, so a domain change needs no code change.
 */
$origins = array_values(array_filter(array_map(
    fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins ?: [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5174',
        'http://localhost:5175',
        'http://127.0.0.1:5175',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    // Let browsers cache the preflight (Chrome caps it at 2 h). With 0, every
    // authenticated poll from the SPAs costs an extra OPTIONS request that boots
    // the whole framework — double the PHP work on a shared CPU limit.
    'max_age' => 7200,
    'supports_credentials' => true,
];
