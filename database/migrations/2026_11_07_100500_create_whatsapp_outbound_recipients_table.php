<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per recipient or added group participant of an outbound
     * operation: the unit counted by the daily cap and the non-contact limit
     * (RF-22, RF-25, RF-29).
     */
    public function up(): void
    {
        Schema::create('whatsapp_outbound_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_outbound_message_id')->constrained('whatsapp_outbound_messages')->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->constrained('whatsapp_sessions')->cascadeOnDelete();
            $table->string('jid');
            $table->boolean('is_non_contact')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['whatsapp_session_id', 'created_at']);
            $table->index(['whatsapp_session_id', 'jid', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbound_recipients');
    }
};
