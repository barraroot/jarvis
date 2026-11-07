<?php

namespace Tests\Feature;

use App\Models\ConnectorStatus;
use App\Models\User;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappScheduleStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_whatsapp_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('whatsapp_consents', ['id', 'user_id', 'version', 'scopes', 'consented_at', 'revoked_at', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('whatsapp_sessions', [
            'id', 'user_id', 'wuzapi_user_id', 'token', 'hmac_key', 'webhook_key', 'webhook_key_hash', 'connector_status_id',
            'suspended_at', 'phone_masked', 'last_error', 'last_checked_at', 'connected_at', 'next_delivery_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('whatsapp_schedules', [
            'id', 'user_id', 'whatsapp_session_id', 'approval_id', 'whatsapp_schedule_status_id', 'to_jid', 'kind', 'payload',
            'run_at', 'recurrence', 'next_run_at', 'last_run_at', 'approval_expires_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('whatsapp_outbound_messages', [
            'id', 'user_id', 'whatsapp_session_id', 'whatsapp_outbound_status_id', 'kind', 'to_jid', 'payload', 'meta', 'reason',
            'upstream_id', 'whatsapp_schedule_id', 'approval_id', 'delivery_started_at', 'sent_at', 'finished_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('whatsapp_outbound_recipients', ['id', 'whatsapp_outbound_message_id', 'whatsapp_session_id', 'jid', 'is_non_contact', 'created_at']));
        $this->assertTrue(Schema::hasColumns('whatsapp_messages', [
            'id', 'whatsapp_session_id', 'user_id', 'upstream_id', 'chat_jid', 'sender_jid', 'from_me', 'type', 'text', 'media',
            'whatsapp_outbound_message_id', 'sent_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_status_lookups_are_seeded(): void
    {
        foreach ([WhatsappOutboundStatus::QUEUED, WhatsappOutboundStatus::SENT, WhatsappOutboundStatus::FAILED, WhatsappOutboundStatus::BLOCKED] as $slug) {
            $this->assertIsInt(WhatsappOutboundStatus::idFor($slug));
        }

        foreach ([WhatsappScheduleStatus::ACTIVE, WhatsappScheduleStatus::PAUSED, WhatsappScheduleStatus::CANCELLED, WhatsappScheduleStatus::COMPLETED] as $slug) {
            $this->assertIsInt(WhatsappScheduleStatus::idFor($slug));
        }

        $this->assertSame(4, DB::table('whatsapp_outbound_statuses')->count());
        $this->assertSame(4, DB::table('whatsapp_schedule_statuses')->count());
    }

    public function test_session_is_unique_per_user(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn (): int => $this->insertSession($user));
    }

    public function test_webhook_key_hash_is_unique(): void
    {
        $this->insertSession(User::factory()->create(), 'same-key');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn (): int => $this->insertSession(User::factory()->create(), 'same-key'));
    }

    public function test_consent_is_unique_per_user(): void
    {
        $user = User::factory()->create();
        $this->insertConsent($user);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn (): int => $this->insertConsent($user));
    }

    public function test_message_upstream_id_is_unique_per_session(): void
    {
        $sessionId = $this->insertSession(User::factory()->create());
        $otherSessionId = $this->insertSession(User::factory()->create());

        $this->insertMessage($sessionId, 'ABC123');
        $this->insertMessage($otherSessionId, 'ABC123');

        $this->assertSame(2, DB::table('whatsapp_messages')->count());

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn (): int => $this->insertMessage($sessionId, 'ABC123'));
    }

    public function test_deleting_session_cascades_to_dependent_rows(): void
    {
        $user = User::factory()->create();
        $sessionId = $this->insertSession($user);
        $scheduleId = DB::table('whatsapp_schedules')->insertGetId([
            'user_id' => $user->id,
            'whatsapp_session_id' => $sessionId,
            'whatsapp_schedule_status_id' => WhatsappScheduleStatus::idFor(WhatsappScheduleStatus::ACTIVE),
            'to_jid' => '5511999990000@s.whatsapp.net',
            'kind' => 'text',
            'payload' => json_encode(['text' => 'Bom dia']),
            'recurrence' => '0 9 * * *',
            'next_run_at' => now()->addDay(),
            'approval_expires_at' => now()->addDays(90),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $outboundId = DB::table('whatsapp_outbound_messages')->insertGetId([
            'user_id' => $user->id,
            'whatsapp_session_id' => $sessionId,
            'whatsapp_outbound_status_id' => WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::QUEUED),
            'kind' => 'text',
            'to_jid' => '5511999990000@s.whatsapp.net',
            'payload' => json_encode(['text' => 'Bom dia']),
            'whatsapp_schedule_id' => $scheduleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('whatsapp_outbound_recipients')->insert([
            'whatsapp_outbound_message_id' => $outboundId,
            'whatsapp_session_id' => $sessionId,
            'jid' => '5511999990000@s.whatsapp.net',
            'is_non_contact' => true,
            'created_at' => now(),
        ]);
        $messageId = $this->insertMessage($sessionId, 'XYZ');
        DB::table('whatsapp_messages')->where('id', $messageId)->update(['whatsapp_outbound_message_id' => $outboundId]);

        DB::table('whatsapp_outbound_messages')->where('id', $outboundId)->delete();

        $this->assertNull(DB::table('whatsapp_messages')->where('id', $messageId)->value('whatsapp_outbound_message_id'));
        $this->assertSame(0, DB::table('whatsapp_outbound_recipients')->count());

        DB::table('whatsapp_sessions')->where('id', $sessionId)->delete();

        foreach (['whatsapp_schedules', 'whatsapp_outbound_messages', 'whatsapp_outbound_recipients', 'whatsapp_messages'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Rows left in [{$table}].");
        }
    }

    public function test_migrations_roll_back_and_reapply(): void
    {
        $tables = [
            'whatsapp_outbound_statuses', 'whatsapp_schedule_statuses', 'whatsapp_consents', 'whatsapp_sessions',
            'whatsapp_schedules', 'whatsapp_outbound_messages', 'whatsapp_outbound_recipients', 'whatsapp_messages',
        ];
        $migrations = glob(database_path('migrations/*_create_whatsapp_*.php')) ?: [];

        $this->assertCount(7, $migrations);

        Artisan::call('migrate:rollback', ['--step' => 0, '--force' => true, '--path' => array_map(fn (string $file): string => str_replace(base_path().'/', '', $file), $migrations)]);

        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table [{$table}] survived the rollback.");
        }

        Artisan::call('migrate', ['--force' => true]);

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table [{$table}] is missing after migrate.");
        }
    }

    private function insertSession(User $user, ?string $webhookKey = null): int
    {
        $webhookKey ??= Str::random(48);

        return DB::table('whatsapp_sessions')->insertGetId([
            'user_id' => $user->id,
            'token' => 'encrypted-token',
            'hmac_key' => 'encrypted-hmac',
            'webhook_key' => 'encrypted-webhook-key',
            'webhook_key_hash' => hash('sha256', $webhookKey),
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::PENDING),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertConsent(User $user): int
    {
        return DB::table('whatsapp_consents')->insertGetId([
            'user_id' => $user->id,
            'version' => 1,
            'scopes' => json_encode(['leitura']),
            'consented_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMessage(int $sessionId, string $upstreamId): int
    {
        return DB::table('whatsapp_messages')->insertGetId([
            'whatsapp_session_id' => $sessionId,
            'user_id' => DB::table('whatsapp_sessions')->where('id', $sessionId)->value('user_id'),
            'upstream_id' => $upstreamId,
            'chat_jid' => '5511999990000@s.whatsapp.net',
            'sender_jid' => '5511999990000@s.whatsapp.net',
            'type' => 'text',
            'text' => 'Olá',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
