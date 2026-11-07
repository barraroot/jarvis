<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages received through the wuzapi webhook (RF-36), stored as
     * untrusted data and deduplicated per session by `upstream_id`. Media
     * keeps metadata only (`media`), never the binary.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_session_id')->constrained('whatsapp_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('upstream_id', 128);
            $table->string('chat_jid');
            $table->string('sender_jid')->nullable();
            $table->boolean('from_me')->default(false);
            $table->string('type', 16);
            $table->text('text')->nullable();
            $table->jsonb('media')->nullable();
            $table->foreignId('whatsapp_outbound_message_id')->nullable()->constrained('whatsapp_outbound_messages')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_session_id', 'upstream_id']);
            $table->index(['whatsapp_session_id', 'chat_jid', 'sent_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
