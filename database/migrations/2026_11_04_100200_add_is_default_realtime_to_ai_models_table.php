<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default model for the `realtime` capability (RF-09a), unique like the
     * text/embedding defaults.
     */
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->boolean('is_default_realtime')->default(false);
        });

        DB::statement('CREATE UNIQUE INDEX ai_models_default_realtime_unique ON ai_models (is_default_realtime) WHERE is_default_realtime');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_models_default_realtime_unique');

        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropColumn('is_default_realtime');
        });
    }
};
