<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
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
}
