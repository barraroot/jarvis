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
        Schema::create('tools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_type_id')->constrained('tool_types')->restrictOnDelete();
            $table->foreignId('mcp_tool_definition_id')->nullable()->unique()->constrained('mcp_tool_definitions')->nullOnDelete();
            $table->string('identifier')->unique();
            $table->string('name');
            $table->text('description');
            $table->jsonb('input_schema');
            $table->jsonb('output_schema');
            $table->integer('timeout_seconds')->default(30);
            $table->boolean('is_write')->default(true);
            $table->boolean('is_sensitive')->default(true);
            $table->string('handler_class')->nullable();
            $table->string('endpoint_url')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->text('credential')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
