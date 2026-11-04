<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Version of the wake word consent text the user accepted (RF-22); older
     * or missing versions require a new consent.
     */
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('wake_word_consent_version')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->dropColumn('wake_word_consent_version');
        });
    }
};
