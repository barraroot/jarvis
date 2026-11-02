<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| The SPA authenticates with Sanctum cookies, so credentials must be allowed
| and the origins must be listed explicitly (wildcards are not permitted
| together with credentials). FRONTEND_URL covers a Vite dev server or a
| separately hosted build; APP_URL covers the SPA served by Laravel itself.
|
*/

$origins = array_values(array_unique(array_filter([
    env('APP_URL', 'http://localhost'),
    env('FRONTEND_URL', 'http://localhost:5173'),
    'http://localhost',
    'http://localhost:5173',
    'http://127.0.0.1',
    'http://127.0.0.1:5173',
])));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 0,

    'supports_credentials' => true,

];
