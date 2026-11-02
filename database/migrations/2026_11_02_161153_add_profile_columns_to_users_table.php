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
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('remember_token');
            $table->boolean('is_active')->default(true)->after('is_admin');
            $table->decimal('monthly_cost_limit', 10, 2)->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('monthly_cost_limit');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['is_admin', 'is_active', 'monthly_cost_limit', 'last_login_at']);
        });
    }
};
