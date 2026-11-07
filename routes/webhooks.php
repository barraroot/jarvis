<?php

use App\Http\Controllers\Webhooks\WuzapiWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public webhook receivers, loaded from `bootstrap/app.php` outside the `api`
| group: no session, no Sanctum and no per-user `throttle:api` (CT-09, D6).
| Each receiver authenticates the request itself.
*/

Route::post('api/v1/webhooks/wuzapi/{webhook_key}', WuzapiWebhookController::class)
    ->where('webhook_key', '[A-Za-z0-9]{32,128}')
    ->name('api.webhooks.wuzapi');
