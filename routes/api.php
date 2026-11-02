<?php

use App\Http\Controllers\Api\V1\Ai\ModelController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Conversations\ConversationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Consumed by the SPA through Sanctum's stateful cookie mode. There is no
| self-registration: accounts are created by an administrator in Filament.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('login', LoginController::class)
            ->middleware('throttle:login')
            ->name('login');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::post('logout', LogoutController::class)->name('logout');
            Route::get('me', MeController::class)->name('me');
        });

        Route::get('ai/models', ModelController::class)->name('ai.models');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
    });
});
