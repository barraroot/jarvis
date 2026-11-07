<?php

namespace Tests\Feature;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\LookupModel;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\LookupSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);

        $this->settings = app(Settings::class);

        Cache::flush();
    }

    public function test_get_returns_seeded_default(): void
    {
        $this->assertSame(3, $this->settings->get('brain.max_delegation_depth'));
        $this->assertSame(0.75, $this->settings->get('memory.similarity_threshold'));
        $this->assertSame('fallback', $this->settings->get('does.not.exist', 'fallback'));

        $this->assertSame(3, $this->settings->maxDelegationDepth());
        $this->assertSame(30, $this->settings->toolTimeoutSeconds());
        $this->assertSame(20, $this->settings->maxToolCalls());
        $this->assertSame(900, $this->settings->taskTimeoutSeconds());
        $this->assertSame(60, $this->settings->messagesPerMinute());
        $this->assertSame(24, $this->settings->approvalTtlHours());
        $this->assertSame(5, $this->settings->memoryTopN());
        $this->assertSame(0.75, $this->settings->memorySimilarityThreshold());
        $this->assertSame(0.92, $this->settings->memoryDuplicateThreshold());
        $this->assertSame(20, $this->settings->contextMessages());
        $this->assertSame(5, $this->settings->connectorHealthCheckMinutes());
        $this->assertSame(90, $this->settings->toolExecutionsRetentionDays());
        $this->assertSame(90, $this->settings->taskLogsRetentionDays());
        $this->assertSame(24, $this->settings->usageRecordsRetentionMonths());
        $this->assertSame(30, $this->settings->readNotificationsRetentionDays());
    }

    public function test_set_updates_value_records_author_and_busts_cache(): void
    {
        $admin = User::factory()->create();

        $this->assertSame(5, $this->settings->memoryTopN());
        $this->assertTrue(Cache::has(Settings::CACHE_PREFIX.'memory.top_n'));

        $this->settings->set('memory.top_n', 12, $admin);

        $this->assertFalse(Cache::has(Settings::CACHE_PREFIX.'memory.top_n'));
        $this->assertSame(12, $this->settings->memoryTopN());

        $setting = Setting::query()->where('key', 'memory.top_n')->firstOrFail();
        $this->assertSame(12, $setting->value);
        $this->assertTrue($setting->updatedBy->is($admin));
        $this->assertSame(12, json_decode(DB::table('settings')->where('key', 'memory.top_n')->value('value')));
    }

    public function test_get_is_served_from_cache_after_first_read(): void
    {
        $this->assertSame(24, $this->settings->approvalTtlHours());

        DB::table('settings')->where('key', 'brain.approval_ttl_hours')->update(['value' => json_encode(48)]);

        $this->assertSame(24, $this->settings->approvalTtlHours(), 'Direct DB writes must not be visible until the cache is busted.');

        $this->settings->forget('brain.approval_ttl_hours');

        $this->assertSame(48, $this->settings->approvalTtlHours());
    }

    public function test_set_creates_missing_key_and_stores_arrays(): void
    {
        $setting = $this->settings->set('ui.theme', ['accent' => 'cyan'], null);

        $this->assertNull($setting->updated_by_user_id);
        $this->assertSame(['accent' => 'cyan'], $this->settings->get('ui.theme'));
    }

    public function test_voice_listening_window_defaults_to_60(): void
    {
        $this->assertSame(60, $this->settings->listeningWindowSeconds());
        $this->assertSame(200000, $this->settings->sessionTokenCeiling());
        $this->assertSame('marin', $this->settings->realtimeVoice());

        DB::table('settings')->whereIn('key', ['voice.listening_window_seconds', 'voice.session_token_ceiling', 'voice.realtime_voice'])->delete();
        Cache::flush();

        $this->assertSame(60, $this->settings->listeningWindowSeconds());
        $this->assertSame(200000, $this->settings->sessionTokenCeiling());
        $this->assertSame('marin', $this->settings->realtimeVoice());

        $this->assertSame(['min' => 15, 'max' => 300, 'type' => 'int'], Settings::RANGES['voice.listening_window_seconds']);
        $this->assertSame(['min' => 10000, 'max' => 2000000, 'type' => 'int'], Settings::RANGES['voice.session_token_ceiling']);
    }

    public function test_realtime_voice_update_is_audited(): void
    {
        $this->seed(LookupSeeder::class);
        LookupModel::flushCache();

        $admin = User::factory()->create();

        $changed = $this->settings->update(['voice.realtime_voice' => 'cedar', 'voice.listening_window_seconds' => '90'], $admin);

        $this->assertSame(['voice.realtime_voice', 'voice.listening_window_seconds'], $changed);
        $this->assertSame('cedar', $this->settings->realtimeVoice());
        $this->assertSame(90, $this->settings->listeningWindowSeconds());

        $log = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::SETTING_UPDATED))
            ->where('properties->key', 'voice.realtime_voice')
            ->sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('marin', $log->properties['old']);
        $this->assertSame('cedar', $log->properties['new']);

        $this->assertSame([], $this->settings->update(['voice.realtime_voice' => 'cedar'], $admin), 'Unchanged values are not audited again.');
    }

    public function test_realtime_voice_rejects_unknown_value(): void
    {
        $this->seed(LookupSeeder::class);
        LookupModel::flushCache();

        $admin = User::factory()->create();

        $this->assertSame([], $this->settings->update(['voice.realtime_voice' => 'nova'], $admin));
        $this->assertSame([], $this->settings->update(['voice.realtime_voice' => ['cedar']], $admin));

        $this->assertSame('marin', $this->settings->realtimeVoice());
        $this->assertSame(0, AuditLog::query()->count());

        $this->settings->set('voice.realtime_voice', 'nova');

        $this->assertSame('marin', $this->settings->realtimeVoice(), 'A value outside the list falls back to the default voice.');
    }

    public function test_whatsapp_accessors_return_ct40_defaults_without_stored_setting(): void
    {
        DB::table('settings')->delete();
        Cache::flush();

        $this->assertFalse($this->settings->whatsappKillSwitchActive());
        $this->assertSame(6, $this->settings->whatsappMaxSendsPerMinute());
        $this->assertSame(10, $this->settings->whatsappMaxSendsPerRecipientPerHour());
        $this->assertSame(200, $this->settings->whatsappDailySendCap());
        $this->assertSame(8, $this->settings->whatsappMinSendIntervalSeconds());
        $this->assertSame(2, $this->settings->whatsappJitterMinSeconds());
        $this->assertSame(7, $this->settings->whatsappJitterMaxSeconds());
        $this->assertSame(1500, $this->settings->whatsappTypingMinMs());
        $this->assertSame(6000, $this->settings->whatsappTypingMaxMs());
        $this->assertSame(5, $this->settings->whatsappMaxNonContactRecipients());
        $this->assertSame(60, $this->settings->whatsappNonContactWindowMinutes());
        $this->assertSame(30, $this->settings->whatsappMessagesRetentionDays());

        $this->settings->set('whatsapp.kill_switch', 1);

        $this->assertTrue($this->settings->whatsappKillSwitchActive());
    }

    public function test_whatsapp_ranges_follow_ct40(): void
    {
        $expected = [
            'whatsapp.kill_switch' => [0, 1],
            'whatsapp.max_sends_per_minute' => [2, 30],
            'whatsapp.max_sends_per_recipient_per_hour' => [3, 50],
            'whatsapp.daily_send_cap' => [50, 1000],
            'whatsapp.min_send_interval_seconds' => [2, 40],
            'whatsapp.jitter_min_seconds' => [1, 10],
            'whatsapp.jitter_max_seconds' => [2, 35],
            'whatsapp.typing_min_ms' => [375, 7500],
            'whatsapp.typing_max_ms' => [1500, 30000],
            'whatsapp.max_non_contact_recipients' => [2, 25],
            'whatsapp.non_contact_window_minutes' => [15, 300],
            'retention.whatsapp_messages_days' => [1, null],
        ];

        foreach ($expected as $key => [$min, $max]) {
            $this->assertSame(['min' => $min, 'max' => $max, 'type' => 'int'], Settings::RANGES[$key], "Unexpected range for [{$key}].");
            $this->assertArrayHasKey($key, Settings::WHATSAPP_DEFAULTS);
        }

        $this->assertSame(array_keys($expected), array_keys(Settings::WHATSAPP_DEFAULTS));
    }

    public function test_whatsapp_setting_update_is_audited_and_applies_immediately(): void
    {
        $this->seed(LookupSeeder::class);
        LookupModel::flushCache();

        $admin = User::factory()->create();

        $this->assertSame(6, $this->settings->whatsappMaxSendsPerMinute());

        $changed = $this->settings->update(['whatsapp.max_sends_per_minute' => 8], $admin);

        $this->assertSame(['whatsapp.max_sends_per_minute'], $changed);
        $this->assertSame(8, $this->settings->whatsappMaxSendsPerMinute());

        $log = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::SETTING_UPDATED))
            ->where('properties->key', 'whatsapp.max_sends_per_minute')
            ->sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(6, $log->properties['old']);
        $this->assertSame(8, $log->properties['new']);
    }

    public function test_cross_field_errors_flag_inverted_jitter_and_typing_windows(): void
    {
        $this->assertSame([], $this->settings->crossFieldErrors([]));
        $this->assertSame([], $this->settings->crossFieldErrors(['whatsapp.jitter_min_seconds' => 5, 'whatsapp.jitter_max_seconds' => 5]));

        $errors = $this->settings->crossFieldErrors(['whatsapp.jitter_min_seconds' => 9, 'whatsapp.jitter_max_seconds' => 3]);

        $this->assertSame(['whatsapp.jitter_max_seconds'], array_keys($errors));
        $this->assertStringContainsString('deve ser maior ou igual', $errors['whatsapp.jitter_max_seconds']);

        $this->assertArrayHasKey('whatsapp.jitter_max_seconds', $this->settings->crossFieldErrors(['whatsapp.jitter_min_seconds' => 8]), 'Stored jitter_max (7) is used when not submitted.');

        $this->settings->set('whatsapp.typing_max_ms', 2000);

        $this->assertSame(['whatsapp.typing_max_ms'], array_keys($this->settings->crossFieldErrors(['whatsapp.typing_min_ms' => 2500])));
    }
}
