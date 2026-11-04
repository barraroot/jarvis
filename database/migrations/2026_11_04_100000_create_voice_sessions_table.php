<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Realtime wake session (RF-21): ties the conversation, the
     * realtime model and the reported token total used by the session ceiling.
     */
    public function up(): void
    {
        Schema::create('voice_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained('ai_models')->restrictOnDelete();
            $table->integer('total_tokens')->default(0);
            $table->timestamp('last_issued_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_sessions');
    }
};
