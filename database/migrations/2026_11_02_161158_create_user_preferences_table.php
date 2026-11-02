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
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->default('pt-BR');
            $table->string('speech_locale', 10)->default('pt-BR');
            $table->string('voice_name')->nullable();
            $table->decimal('speech_rate', 3, 2)->default(1.00);
            $table->boolean('spoken_responses_enabled')->default(true);
            $table->boolean('wake_word_enabled')->default(false);
            $table->timestamp('wake_word_consented_at')->nullable();
            $table->integer('silence_timeout_ms')->default(1500);
            $table->jsonb('extra')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
