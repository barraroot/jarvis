<?php

use App\Http\Controllers\Api\V1\Activity\ActivityController;
use App\Http\Controllers\Api\V1\Agents\AgentController;
use App\Http\Controllers\Api\V1\Agents\AgentExecutionController;
use App\Http\Controllers\Api\V1\Ai\ModelController;
use App\Http\Controllers\Api\V1\Approvals\ApprovalController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Connectors\ConnectorController;
use App\Http\Controllers\Api\V1\Connectors\ConnectorToolController;
use App\Http\Controllers\Api\V1\Conversations\ConversationController;
use App\Http\Controllers\Api\V1\Conversations\ConversationMessageController;
use App\Http\Controllers\Api\V1\Memories\MemoryController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Preferences\PreferencesController;
use App\Http\Controllers\Api\V1\Tasks\TaskController;
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

        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::get('agent-executions', [AgentExecutionController::class, 'index'])->name('agent-executions.index');

        Route::get('activity', ActivityController::class)->name('activity.index');

        Route::get('preferences', [PreferencesController::class, 'show'])->name('preferences.show');
        Route::put('preferences', [PreferencesController::class, 'update'])->name('preferences.update');

        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
        Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
        Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
            ->middleware('throttle:messages')
            ->name('conversations.messages.store');

        Route::get('memories', [MemoryController::class, 'index'])->name('memories.index');
        Route::post('memories', [MemoryController::class, 'store'])->name('memories.store');
        Route::patch('memories/{memory}', [MemoryController::class, 'update'])->name('memories.update');
        Route::delete('memories/{memory}', [MemoryController::class, 'destroy'])->name('memories.destroy');
        Route::post('memories/{memory}/confirm', [MemoryController::class, 'confirm'])->name('memories.confirm');
        Route::post('memories/{memory}/discard', [MemoryController::class, 'discard'])->name('memories.discard');

        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::get('approvals/{approval}', [ApprovalController::class, 'show'])->name('approvals.show');
        Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])
            ->middleware('throttle:approvals')
            ->name('approvals.approve');
        Route::post('approvals/{approval}/deny', [ApprovalController::class, 'deny'])
            ->middleware('throttle:approvals')
            ->name('approvals.deny');

        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
        Route::post('tasks/{task}/retry', [TaskController::class, 'retry'])->name('tasks.retry');

        Route::get('connectors', [ConnectorController::class, 'index'])->name('connectors.index');
        Route::post('connectors', [ConnectorController::class, 'store'])->name('connectors.store');
        Route::patch('connectors/{connector}', [ConnectorController::class, 'update'])->name('connectors.update');
        Route::delete('connectors/{connector}', [ConnectorController::class, 'destroy'])->name('connectors.destroy');
        Route::post('connectors/{connector}/resync', [ConnectorController::class, 'resync'])
            ->middleware('throttle:connectors.resync')
            ->name('connectors.resync');
        Route::get('connectors/{connector}/tools', [ConnectorToolController::class, 'index'])->name('connectors.tools.index');
        Route::patch('connectors/{connector}/tools/{tool}', [ConnectorToolController::class, 'update'])->name('connectors.tools.update');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });
});
