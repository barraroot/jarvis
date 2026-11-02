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
        Schema::create('tool_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')->constrained('tools')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('execution_status_id')->constrained('execution_statuses')->restrictOnDelete();
            $table->foreignId('agent_execution_id')->nullable()->constrained('agent_executions')->nullOnDelete();
            $table->foreignId('task_step_id')->nullable()->constrained('task_steps')->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->unique()->constrained('approvals')->nullOnDelete();
            $table->jsonb('input');
            $table->jsonb('output')->nullable();
            $table->text('error')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['tool_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_executions');
    }
};
