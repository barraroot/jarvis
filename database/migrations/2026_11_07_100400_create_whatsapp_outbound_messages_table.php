<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every WhatsApp send and group mutation that passes the anti-block guard
     * (RF-19, RF-29): `queued` until delivered, then exactly one final status
     * (`sent` with `upstream_id`, `failed` or `blocked` with a CT-31 `reason`).
     */
    public function up(): void
    {
        Schema::create('whatsapp_outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->constrained('whatsapp_sessions')->cascadeOnDelete();
            $table->foreignId('whatsapp_outbound_status_id')->constrained('whatsapp_outbound_statuses')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('to_jid');
            $table->jsonb('payload')->default('{}');
            $table->jsonb('meta')->nullable();
            $table->string('reason', 48)->nullable();
            $table->string('upstream_id', 128)->nullable()->index();
            $table->foreignId('whatsapp_schedule_id')->nullable()->constrained('whatsapp_schedules')->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->timestamp('delivery_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_session_id', 'created_at']);
            $table->index(['whatsapp_session_id', 'whatsapp_outbound_status_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbound_messages');
    }
};
