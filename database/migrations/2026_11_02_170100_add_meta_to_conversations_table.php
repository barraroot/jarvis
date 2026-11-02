<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversations keep small flags (e.g. `meta.title_edited` to protect a
     * manually renamed title from the auto-title job, US-2.6).
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->jsonb('meta')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
