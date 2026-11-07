<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LookupTablesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $lookupTables = [
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
        'visualization_statuses',
        'whatsapp_outbound_statuses',
        'whatsapp_schedule_statuses',
    ];

    public function test_all_lookup_tables_exist_with_unique_slug(): void
    {
        foreach ($this->lookupTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table [{$table}] is missing.");
            $this->assertTrue(
                Schema::hasColumns($table, ['id', 'name', 'slug', 'description', 'is_active', 'created_at', 'updated_at']),
                "Table [{$table}] is missing expected columns."
            );

            DB::table($table)->insert(['name' => 'First', 'slug' => 'duplicated', 'created_at' => now(), 'updated_at' => now()]);

            $exception = null;

            try {
                DB::transaction(function () use ($table): void {
                    DB::table($table)->insert(['name' => 'Second', 'slug' => 'duplicated', 'created_at' => now(), 'updated_at' => now()]);
                });
            } catch (UniqueConstraintViolationException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(UniqueConstraintViolationException::class, $exception, "Table [{$table}] accepted a duplicated slug.");
        }
    }

    public function test_lookup_rows_default_to_active_with_null_description(): void
    {
        DB::table('message_roles')->insert(['name' => 'User', 'slug' => 'user', 'created_at' => now(), 'updated_at' => now()]);

        $row = DB::table('message_roles')->where('slug', 'user')->first();

        $this->assertTrue((bool) $row->is_active);
        $this->assertNull($row->description);
    }

    public function test_no_migration_declares_enum_columns(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        foreach ($files as $file) {
            $this->assertStringNotContainsString('->enum(', file_get_contents($file), "Migration [{$file}] declares an enum column.");
        }
    }
}
