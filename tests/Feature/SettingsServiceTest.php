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
}
