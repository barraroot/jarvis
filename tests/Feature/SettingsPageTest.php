<?php

namespace Tests\Feature;

use App\Filament\Pages\SettingsPage;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\SettingsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
        $this->seed(SettingsSeeder::class);
        Cache::flush();

        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $this->get('/admin/settings')->assertOk()->assertSee('Profundidade máxima de delegação');

        $invalid = [
            'brain.max_delegation_depth' => [6, 'max'],
            'brain.tool_timeout_seconds' => [4, 'min'],
            'brain.max_tool_calls' => [101, 'max'],
            'brain.task_timeout_seconds' => [59, 'min'],
            'brain.messages_per_minute' => [601, 'max'],
            'brain.approval_ttl_hours' => [169, 'max'],
            'memory.top_n' => [0, 'min'],
            'memory.similarity_threshold' => [0.49, 'min'],
            'memory.duplicate_threshold' => [1, 'max'],
            'brain.context_messages' => [1, 'min'],
            'retention.task_logs_days' => [0, 'min'],
        ];

        foreach ($invalid as $key => [$value, $rule]) {
            Livewire::test(SettingsPage::class)
                ->fillForm([$key => $value])
                ->call('save')
                ->assertHasFormErrors([$key => $rule]);
        }

        // Lower and upper bounds themselves are accepted.
        Livewire::test(SettingsPage::class)
            ->fillForm([
                'brain.max_delegation_depth' => 0,
                'brain.max_tool_calls' => 100,
                'memory.similarity_threshold' => 0.99,
                'retention.usage_records_months' => 1,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, app(Settings::class)->maxDelegationDepth());
        $this->assertSame(0, AuditLog::query()->where('properties->key', 'brain.messages_per_minute')->count());
    }

    public function test_saving_updates_setting_busts_cache_and_audits(): void
    {
        $settings = app(Settings::class);

        // Warm the cache: the old value must not survive the save.
        $this->assertSame(60, $settings->messagesPerMinute());
        $this->assertSame(0.75, $settings->memorySimilarityThreshold());

        Livewire::test(SettingsPage::class)
            ->assertSchemaStateSet([
                'brain.messages_per_minute' => 60,
                'memory.top_n' => 5,
            ])
            ->fillForm([
                'brain.messages_per_minute' => 120,
                'memory.similarity_threshold' => 0.8,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(120, $settings->messagesPerMinute());
        $this->assertSame(0.8, $settings->memorySimilarityThreshold());

        $setting = Setting::query()->where('key', 'brain.messages_per_minute')->firstOrFail();
        $this->assertSame($this->admin->id, $setting->updated_by_user_id);

        $logs = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::SETTING_UPDATED))
            ->get();

        // Only changed keys are audited.
        $this->assertCount(2, $logs);

        $log = $logs->firstWhere('properties.key', 'brain.messages_per_minute');
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($setting->id, $log->subject_id);
        $this->assertSame(60, $log->properties['old']);
        $this->assertSame(120, $log->properties['new']);

        // The rate limiter reads the setting dynamically: no deploy needed.
        $this->assertSame(120, Cache::get(Settings::CACHE_PREFIX.'brain.messages_per_minute')['value']);
    }
}
