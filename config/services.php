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

    /*
    | WhatsApp connector (wuzapi multi-user, `compose.yaml` service `wuzapi`):
    | reached only on the internal Docker network. `admin_token` authorizes
    | the admin API (`/admin/users`); `webhook_base_url` is the address the
    | wuzapi container uses to call the JARVIS webhook. Outbound media comes
    | from https URLs fetched by the server, capped per type (bytes).
    */
    'wuzapi' => [
        'url' => env('WUZAPI_URL'),
        'admin_token' => env('WUZAPI_ADMIN_TOKEN'),
        'timeout_seconds' => (int) env('WUZAPI_TIMEOUT_SECONDS', 15),
        'webhook_base_url' => env('WUZAPI_WEBHOOK_BASE_URL'),
        'media_max_bytes' => [
            'image' => (int) env('WUZAPI_MEDIA_MAX_IMAGE_BYTES', 5 * 1024 * 1024),
            'video' => (int) env('WUZAPI_MEDIA_MAX_VIDEO_BYTES', 16 * 1024 * 1024),
            'audio' => (int) env('WUZAPI_MEDIA_MAX_AUDIO_BYTES', 16 * 1024 * 1024),
            'document' => (int) env('WUZAPI_MEDIA_MAX_DOCUMENT_BYTES', 100 * 1024 * 1024),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
