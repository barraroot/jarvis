<?php

namespace Tests\Feature;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_audit_log_cannot_be_updated_or_deleted(): void
    {
        $log = AuditLog::factory()->create(['ip_address' => '10.0.0.1']);

        try {
            $log->update(['ip_address' => '10.0.0.2']);
            $this->fail('Updating an audit log must throw.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Deleting an audit log must throw.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'ip_address' => '10.0.0.1']);
        $this->assertNull(AuditLog::UPDATED_AT);
    }

    public function test_audit_log_relations_and_system_actor(): void
    {
        $subject = User::factory()->create();
        $log = AuditLog::factory()->system()->action(AuditAction::USER_CREATED)->about($subject)->create([
            'properties' => ['name' => $subject->name],
        ]);

        $this->assertNull($log->user);
        $this->assertSame(AuditAction::USER_CREATED, $log->action->slug);
        $this->assertTrue($log->subject->is($subject));
        $this->assertSame(['name' => $subject->name], $log->properties);
    }

    public function test_notification_unread_scope_and_mark_read(): void
    {
        $user = User::factory()->create();
        $unread = Notification::factory()->for($user)->create();
        $read = Notification::factory()->for($user)->read()->create();

        $this->assertSame([$unread->id], Notification::unread()->pluck('id')->all());
        $this->assertTrue($user->notifications->contains($read));

        $this->assertTrue($unread->markRead());
        $this->assertTrue($unread->fresh()->isRead());
        $this->assertSame(0, Notification::unread()->count());
        $this->assertSame('task_completed', $unread->type->slug);
    }

    public function test_usage_record_has_only_created_at_and_decimal_cost(): void
    {
        $record = UsageRecord::factory()->create(['estimated_cost' => 0.001234]);

        $this->assertNull(UsageRecord::UPDATED_AT);
        $this->assertSame('0.001234', $record->fresh()->estimated_cost);
        $this->assertTrue($record->provider->is($record->model->provider));
        $this->assertSame('completed', $record->status->slug);
        $this->assertTrue($record->user->usageRecords->contains($record));
    }
}
