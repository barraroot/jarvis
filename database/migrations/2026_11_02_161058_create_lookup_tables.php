<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lookup tables that replace enum columns across the schema.
     *
     * @var list<string>
     */
    private array $tables = [
        'message_roles',
        'message_sources',
        'result_types',
        'execution_statuses',
        'task_statuses',
        'approval_statuses',
        'memory_statuses',
        'memory_categories',
        'tool_types',
        'connector_statuses',
        'ai_provider_drivers',
        'ai_model_capabilities',
        'notification_types',
        'audit_actions',
        'log_levels',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
