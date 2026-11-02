<?php

namespace Tests\Feature;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\LogLevel;
use App\Models\Memory;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\ToolExecution;
use App\Models\UsageRecord;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

/**
 * Retention pruning (US-12.2, Notes & Conventions): only records past their
 * configured thresholds are deleted; conversations, audit logs and memories
 * are never touched; `--dry-run` only reports.
 */
class RetentionPruneTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $this->settings = app(Settings::class);
        $this->settings->set('retention.tool_executions_days', 30);
        $this->settings->set('retention.task_logs_days', 30);
        $this->settings->set('retention.usage_records_months', 12);
        $this->settings->set('retention.read_notifications_days', 30);
    }

    public function test_prunes_only_records_older_than_configured_thresholds(): void
    {
        $oldExecution = ToolExecution::factory()->for($this->user)->create(['created_at' => now()->subDays(45)]);
        $recentExecution = ToolExecution::factory()->for($this->user)->create(['created_at' => now()->subDays(10)]);

        $oldLog = TaskLog::factory()->create([
            'task_id' => Task::factory()->for($this->user),
            'log_level_id' => LogLevel::idFor(LogLevel::INFO),
            'created_at' => now()->subDays(60),
        ]);
        $recentLog = TaskLog::factory()->create([
            'task_id' => Task::factory()->for($this->user),
            'log_level_id' => LogLevel::idFor(LogLevel::INFO),
            'created_at' => now()->subDays(5),
        ]);

        $oldUsage = UsageRecord::factory()->for($this->user)->create(['created_at' => now()->subMonths(18)]);
        $recentUsage = UsageRecord::factory()->for($this->user)->create(['created_at' => now()->subMonths(2)]);

        $oldRead = Notification::factory()->for($this->user)->create([
            'created_at' => now()->subDays(60),
            'read_at' => now()->subDays(45),
        ]);
        $recentRead = Notification::factory()->for($this->user)->create([
            'created_at' => now()->subDays(10),
            'read_at' => now()->subDays(3),
        ]);
        $oldUnread = Notification::factory()->for($this->user)->create([
            'created_at' => now()->subDays(90),
            'read_at' => null,
        ]);

        $conversation = Conversation::factory()->for($this->user)->create(['created_at' => now()->subDays(400)]);
        $audit = AuditLog::factory()->for($this->user)->create([
            'audit_action_id' => AuditAction::idFor(AuditAction::TOOL_EXECUTED),
            'created_at' => now()->subDays(400),
        ]);
        $memory = Memory::factory()->for($this->user)->create(['created_at' => now()->subDays(400)]);

        $this->artisan('jarvis:retention:prune')
            ->assertSuccessful()
            ->expectsOutputToContain('tool_executions: 1')
            ->expectsOutputToContain('task_logs: 1')
            ->expectsOutputToContain('usage_records: 1')
            ->expectsOutputToContain('notifications: 1');

        $this->assertDatabaseMissing('tool_executions', ['id' => $oldExecution->id]);
        $this->assertDatabaseHas('tool_executions', ['id' => $recentExecution->id]);

        $this->assertDatabaseMissing('task_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('task_logs', ['id' => $recentLog->id]);

        $this->assertDatabaseMissing('usage_records', ['id' => $oldUsage->id]);
        $this->assertDatabaseHas('usage_records', ['id' => $recentUsage->id]);

        $this->assertDatabaseMissing('notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $oldUnread->id]);
    }

    public function test_never_deletes_conversations_or_audit_logs(): void
    {
        $conversation = Conversation::factory()->for($this->user)->create(['created_at' => now()->subYears(5)]);
        $audit = AuditLog::factory()->for($this->user)->create([
            'audit_action_id' => AuditAction::idFor(AuditAction::TOOL_EXECUTED),
            'created_at' => now()->subYears(5),
        ]);
        $memory = Memory::factory()->for($this->user)->create(['created_at' => now()->subYears(5)]);

        ToolExecution::factory()->for($this->user)->create(['created_at' => now()->subYears(5)]);
        TaskLog::factory()->create([
            'task_id' => Task::factory()->for($this->user),
            'log_level_id' => LogLevel::idFor(LogLevel::INFO),
            'created_at' => now()->subYears(5),
        ]);
        UsageRecord::factory()->for($this->user)->create(['created_at' => now()->subYears(5)]);
        Notification::factory()->for($this->user)->create([
            'created_at' => now()->subYears(5),
            'read_at' => now()->subYears(4),
        ]);

        $this->artisan('jarvis:retention:prune')->assertSuccessful();

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id]);
        $this->assertDatabaseHas('memories', ['id' => $memory->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        ToolExecution::factory()->for($this->user)->create(['created_at' => now()->subDays(90)]);
        TaskLog::factory()->create([
            'task_id' => Task::factory()->for($this->user),
            'log_level_id' => LogLevel::idFor(LogLevel::INFO),
            'created_at' => now()->subDays(90),
        ]);
        UsageRecord::factory()->for($this->user)->create(['created_at' => now()->subYears(3)]);
        Notification::factory()->for($this->user)->create([
            'created_at' => now()->subDays(90),
            'read_at' => now()->subDays(60),
        ]);

        $this->artisan('jarvis:retention:prune', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('tool_executions: 1')
            ->expectsOutputToContain('Dry run: nothing deleted.');

        $this->assertSame(1, ToolExecution::query()->count());
        $this->assertSame(1, TaskLog::query()->count());
        $this->assertSame(1, UsageRecord::query()->count());
        $this->assertSame(1, Notification::query()->count());
    }
}
