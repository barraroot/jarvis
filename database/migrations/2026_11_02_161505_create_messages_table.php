<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_role_id')->constrained('message_roles')->restrictOnDelete();
            $table->foreignId('message_source_id')->nullable()->constrained('message_sources')->restrictOnDelete();
            $table->foreignId('execution_status_id')->constrained('execution_statuses')->restrictOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->text('content');
            $table->foreignId('result_type_id')->nullable()->constrained('result_types')->restrictOnDelete();
            $table->jsonb('payload')->nullable();
            $table->jsonb('suggested_actions')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
