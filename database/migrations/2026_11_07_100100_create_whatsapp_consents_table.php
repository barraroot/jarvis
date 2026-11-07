<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned WhatsApp consent with accepted scopes (RF-09): one row per
     * user, invalid while `revoked_at` is set or `version` is outdated.
     */
    public function up(): void
    {
        Schema::create('whatsapp_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->jsonb('scopes')->default('[]');
            $table->timestamp('consented_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_consents');
    }
};
