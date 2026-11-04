<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usage reported by a Realtime session (CT-07) is linked to it and made
     * idempotent per provider `response_id`.
     */
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->foreignId('voice_session_id')->nullable()->constrained('voice_sessions')->nullOnDelete();
            $table->string('provider_response_id', 64)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX usage_records_voice_response_unique ON usage_records (voice_session_id, provider_response_id) WHERE provider_response_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS usage_records_voice_response_unique');

        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voice_session_id');
            $table->dropColumn('provider_response_id');
        });
    }
};
