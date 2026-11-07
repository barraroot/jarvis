<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single (`run_at`) or recurring (5-field cron `recurrence`, interpreted
     * in America/Sao_Paulo) WhatsApp sends authorized by one approval until
     * `approval_expires_at` (RF-30).
     */
    public function up(): void
    {
        Schema::create('whatsapp_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->constrained('whatsapp_sessions')->cascadeOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('whatsapp_schedule_status_id')->constrained('whatsapp_schedule_statuses')->restrictOnDelete();
            $table->string('to_jid');
            $table->string('kind', 16);
            $table->jsonb('payload')->default('{}');
            $table->timestamp('run_at')->nullable();
            $table->string('recurrence', 64)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('approval_expires_at');
            $table->timestamps();

            $table->index(['whatsapp_schedule_status_id', 'next_run_at']);
            $table->index(['user_id', 'next_run_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_schedules');
    }
};
