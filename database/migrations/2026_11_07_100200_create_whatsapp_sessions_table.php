<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One wuzapi user per JARVIS user (RF-01). `token`, `hmac_key` and
     * `webhook_key` hold encrypted secrets (RNF-01); the webhook is looked up
     * by the sha256 `webhook_key_hash`. Suspension is `suspended_at`, not a
     * status; `next_delivery_at` spaces serialized deliveries (RF-23).
     */
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('wuzapi_user_id')->nullable();
            $table->text('token');
            $table->text('hmac_key');
            $table->text('webhook_key');
            $table->char('webhook_key_hash', 64)->unique();
            $table->foreignId('connector_status_id')->constrained('connector_statuses')->restrictOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->string('phone_masked', 32)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('next_delivery_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
    }
};
