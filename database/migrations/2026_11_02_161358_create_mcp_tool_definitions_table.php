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
        Schema::create('mcp_tool_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('input_schema');
            $table->jsonb('output_schema')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['mcp_server_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_definitions');
    }
};
