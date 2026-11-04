<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progressive visualizations built by voice tasks (RF-16..RF-19b); open
     * while `closed_at` is null.
     */
    public function up(): void
    {
        Schema::create('visualizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('visualization_status_id')->constrained('visualization_statuses')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('source', 160)->nullable();
            $table->jsonb('sections')->default('[]');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'closed_at', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visualizations');
    }
};
