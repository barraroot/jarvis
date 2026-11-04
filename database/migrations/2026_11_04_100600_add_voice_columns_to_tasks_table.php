<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voice tasks point to their visualization, the voice session that started
     * them and the model chosen at creation (CT-04).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('visualization_id')->nullable()->constrained('visualizations')->nullOnDelete();
            $table->foreignId('voice_session_id')->nullable()->constrained('voice_sessions')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visualization_id');
            $table->dropConstrainedForeignId('voice_session_id');
            $table->dropConstrainedForeignId('ai_model_id');
        });
    }
};
