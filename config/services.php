<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | MCP connectors (US-9.1/9.2): HTTP/SSE only. Private and loopback hosts are
    | rejected unless explicitly allowed for local development.
    */
    'mcp' => [
        'allow_private_hosts' => (bool) env('MCP_ALLOW_PRIVATE_HOSTS', false),
        'timeout_seconds' => (int) env('MCP_TIMEOUT_SECONDS', 15),
    ],

    /*
    | Reverb health probe used by `/up`: defaults to the broadcasting host
    | (`REVERB_SCHEME://REVERB_HOST:REVERB_PORT/up`); set it when the app
    | reaches Reverb through another address (e.g. `http://reverb:8080/up`).
    |
    | `public` is the address the browser uses (the frontend build's
    | `VITE_REVERB_*`), allowed in the SPA CSP `connect-src`. It differs from
    | `REVERB_HOST` when the backend reaches Reverb on an internal host
    | (Sail: `reverb`) while the browser uses `localhost`.
    */
    'reverb' => [
        'health_url' => env('REVERB_HEALTH_URL'),
        'public' => [
            'host' => env('VITE_REVERB_HOST'),
            'port' => env('VITE_REVERB_PORT'),
            'scheme' => env('VITE_REVERB_SCHEME'),
        ],
    ],

    /*
    | Origin of the OpenAI Realtime API reached by the browser (SDP exchange
    | over WebRTC). Allowed in the SPA CSP `connect-src` only when it is an
    | `https://` origin without wildcards, path or credentials (RNF-08).
    */
    'openai_realtime' => [
        'origin' => env('OPENAI_REALTIME_ORIGIN', 'https://api.openai.com'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
