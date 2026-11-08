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

    public function test_listening_window_out_of_range_is_rejected(): void
    {
        $this->get('/admin/settings')->assertOk()->assertSee('Janela de escuta (s)');

        foreach ([10 => 'min', 301 => 'max'] as $value => $rule) {
            Livewire::test(SettingsPage::class)
                ->fillForm(['voice.listening_window_seconds' => $value])
                ->call('save')
                ->assertHasFormErrors(['voice.listening_window_seconds' => $rule]);
        }

        $this->assertSame(60, app(Settings::class)->listeningWindowSeconds());

        Livewire::test(SettingsPage::class)
            ->fillForm(['voice.listening_window_seconds' => 30])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(30, app(Settings::class)->listeningWindowSeconds());
    }

    public function test_realtime_voice_can_be_saved_and_is_audited(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertSchemaStateSet(['voice.realtime_voice' => 'marin'])
            ->fillForm(['voice.realtime_voice' => 'not-a-voice'])
            ->call('save')
            ->assertHasFormErrors(['voice.realtime_voice']);

        Livewire::test(SettingsPage::class)
            ->fillForm(['voice.realtime_voice' => 'cedar'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('cedar', app(Settings::class)->realtimeVoice());

        $log = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::SETTING_UPDATED))
            ->where('properties->key', 'voice.realtime_voice')
            ->sole();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('marin', $log->properties['old']);
        $this->assertSame('cedar', $log->properties['new']);
    }

    public function test_whatsapp_section_validates_ranges_and_audits_changes(): void
    {
        $this->get('/admin/settings')
            ->assertOk()
            ->assertSee('WhatsApp — freios e kill switch')
            ->assertSee('Envios por minuto (por sessão)')
            ->assertSee('Retenção de mensagens WhatsApp (dias)');

        Livewire::test(SettingsPage::class)
            ->fillForm(['whatsapp.max_sends_per_minute' => 31])
            ->call('save')
            ->assertHasFormErrors(['whatsapp.max_sends_per_minute' => 'max']);

        $this->assertSame(6, app(Settings::class)->whatsappMaxSendsPerMinute());

        Livewire::test(SettingsPage::class)
            ->fillForm(['whatsapp.max_sends_per_minute' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(8, app(Settings::class)->whatsappMaxSendsPerMinute());

        $logs = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::SETTING_UPDATED))
            ->get();

        $this->assertCount(1, $logs);
        $this->assertSame('whatsapp.max_sends_per_minute', $logs[0]->properties['key']);
        $this->assertSame(6, $logs[0]->properties['old']);
        $this->assertSame(8, $logs[0]->properties['new']);
    }

    public function test_whatsapp_cross_field_rule_rejects_jitter_min_above_max(): void
    {
        Livewire::test(SettingsPage::class)
            ->fillForm([
                'whatsapp.jitter_min_seconds' => 8,
                'whatsapp.jitter_max_seconds' => 7,
                'whatsapp.max_sends_per_minute' => 10,
            ])
            ->call('save')
            ->assertHasErrors(['data.whatsapp.jitter_max_seconds']);

        // Nothing is persisted when the cross-field rule fails.
        $settings = app(Settings::class);
        $this->assertSame(2, $settings->whatsappJitterMinSeconds());
        $this->assertSame(7, $settings->whatsappJitterMaxSeconds());
        $this->assertSame(6, $settings->whatsappMaxSendsPerMinute());
        $this->assertSame(0, AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::SETTING_UPDATED))->count());
    }

    public function test_kill_switch_is_a_toggle_persisted_as_one(): void
    {
        Livewire::test(SettingsPage::class)
            ->assertSchemaStateSet(['whatsapp.kill_switch' => false])
            ->fillForm(['whatsapp.kill_switch' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Setting::query()->where('key', 'whatsapp.kill_switch')->firstOrFail()->value);
        $this->assertTrue(app(Settings::class)->whatsappKillSwitchActive());

        $log = AuditLog::query()->where('properties->key', 'whatsapp.kill_switch')->sole();
        $this->assertSame(0, $log->properties['old']);
        $this->assertSame(1, $log->properties['new']);

        Livewire::test(SettingsPage::class)
            ->assertSchemaStateSet(['whatsapp.kill_switch' => true]);
    }
}
