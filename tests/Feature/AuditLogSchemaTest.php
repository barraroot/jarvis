<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_have_no_updated_at_column(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'created_at'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));
    }

    public function test_audit_log_keeps_row_when_actor_is_deleted(): void
    {
        $user = User::factory()->create();
        $actionId = $this->insertLookup('audit_actions', 'login_succeeded');

        $logId = DB::table('audit_logs')->insertGetId([
            'audit_action_id' => $actionId,
            'user_id' => $user->id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $log = DB::table('audit_logs')->find($logId);

        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
    }

    public function test_usage_records_have_only_created_at_and_zero_defaults(): void
    {
        $this->assertFalse(Schema::hasColumn('usage_records', 'updated_at'));

        $user = User::factory()->create();
        $driverId = $this->insertLookup('ai_provider_drivers', 'openai');
        $statusId = $this->insertLookup('execution_statuses', 'completed');

        $providerId = DB::table('ai_providers')->insertGetId([
            'ai_provider_driver_id' => $driverId,
            'name' => 'OpenAI',
            'slug' => 'openai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modelId = DB::table('ai_models')->insertGetId([
            'ai_provider_id' => $providerId,
            'name' => 'gpt-4.1',
            'model_identifier' => 'gpt-4.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recordId = DB::table('usage_records')->insertGetId([
            'user_id' => $user->id,
            'ai_provider_id' => $providerId,
            'ai_model_id' => $modelId,
            'execution_status_id' => $statusId,
            'created_at' => now(),
        ]);

        $record = DB::table('usage_records')->find($recordId);

        $this->assertSame(0, (int) $record->input_tokens);
        $this->assertSame(0, (int) $record->output_tokens);
        $this->assertSame(0.0, (float) $record->estimated_cost);
    }

    public function test_notifications_table_is_application_specific(): void
    {
        $this->assertTrue(Schema::hasColumns('notifications', ['user_id', 'notification_type_id', 'title', 'body', 'data', 'read_at']));
        $this->assertFalse(Schema::hasColumn('notifications', 'notifiable_type'));

        $user = User::factory()->create();
        $typeId = $this->insertLookup('notification_types', 'task_completed');

        DB::table('notifications')->insert([
            'user_id' => $user->id,
            'notification_type_id' => $typeId,
            'title' => 'Task completed',
            'data' => json_encode(['task_id' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id]);
    }

    private function insertLookup(string $table, string $slug): int
    {
        return DB::table($table)->insertGetId([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
