<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| The SPA authenticates with Sanctum cookies, so credentials must be allowed
| and the origins must be listed explicitly (wildcards are not permitted
| together with credentials). FRONTEND_URL covers a Vite dev server or a
| separately hosted build; APP_URL covers the SPA served by Laravel itself;
| CORS_ALLOWED_ORIGINS (comma separated) adds any other origin. Nothing is
| hardcoded: development origins come from the local .env.
|
*/

$origins = array_values(array_unique(array_filter(array_map(
    fn (string $origin): string => rtrim(trim($origin), '/'),
    [
        (string) env('APP_URL', ''),
        (string) env('FRONTEND_URL', ''),
        ...explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ],
))));

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
