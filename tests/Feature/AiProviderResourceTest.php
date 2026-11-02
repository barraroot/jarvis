<?php

namespace Tests\Feature;

use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Models\AiProvider;
use App\Models\AiProviderDriver;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AiProviderResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->setUpSeedsLookups();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_edit_form_never_renders_full_api_key(): void
    {
        $provider = AiProvider::factory()->create(['api_key' => 'sk-super-secret-full-key-9876']);

        $response = $this->get('/admin/ai-providers/'.$provider->id.'/edit');

        $response->assertOk();
        $this->assertStringNotContainsString('sk-super-secret-full-key-9876', $response->getContent());
        $this->assertStringContainsString('****9876', $response->getContent());

        $this->get('/admin/ai-providers')->assertOk();
    }

    public function test_blank_api_key_on_update_keeps_existing_key(): void
    {
        $provider = AiProvider::factory()->create(['slug' => 'keep-openai', 'api_key' => 'sk-original-key']);

        Livewire::test(EditAiProvider::class, ['record' => $provider->getRouteKey()])
            ->fillForm([
                'name' => 'Renomeado',
                'slug' => 'keep-openai',
                'ai_provider_driver_id' => $provider->ai_provider_driver_id,
                'api_key' => '',
                'base_url' => null,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $provider->refresh();
        $this->assertSame('Renomeado', $provider->name);
        $this->assertSame('sk-original-key', $provider->api_key);

        Livewire::test(EditAiProvider::class, ['record' => $provider->getRouteKey()])
            ->fillForm([
                'name' => 'Renomeado',
                'slug' => 'keep-openai',
                'ai_provider_driver_id' => $provider->ai_provider_driver_id,
                'api_key' => 'sk-new-key',
                'base_url' => null,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-new-key', $provider->fresh()->api_key);
    }

    public function test_test_connection_action_records_result(): void
    {
        Ai::fakeAgent(AnonymousAgent::class, ['OK']);

        $provider = AiProvider::factory()->create([
            'name' => 'Conectável',
            'last_tested_at' => null,
            'last_test_error' => 'erro antigo',
        ]);

        Livewire::test(ListAiProviders::class)
            ->callTableAction('testConnection', $provider)
            ->assertHasNoTableActionErrors();

        $provider->refresh();
        $this->assertNotNull($provider->last_tested_at);
        $this->assertNull($provider->last_test_error);

        // A failing provider records the error instead. The provider list is
        // cached for 60 s, so flush it to make the new provider visible.
        Cache::flush();

        Ai::fakeAgent(AnonymousAgent::class, fn (): never => throw new RuntimeException('401 Unauthorized'));

        $failing = AiProvider::factory()->create(['name' => 'Falho']);

        Livewire::test(ListAiProviders::class)
            ->callTableAction('testConnection', $failing)
            ->assertHasNoTableActionErrors();

        $failing->refresh();
        $this->assertNotNull($failing->last_tested_at);
        $this->assertStringContainsString('401 Unauthorized', $failing->last_test_error);
    }

    public function test_provider_changes_are_audited(): void
    {
        Livewire::test(CreateAiProvider::class)
            ->fillForm([
                'name' => 'Auditado',
                'slug' => 'audited-openai',
                'ai_provider_driver_id' => AiProviderDriver::idFor(AiProviderDriver::OPENAI),
                'api_key' => 'sk-audited-key',
                'base_url' => null,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $provider = AiProvider::query()->where('slug', 'audited-openai')->firstOrFail();

        $created = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::PROVIDER_CREATED))->firstOrFail();
        $this->assertTrue($created->user->is($this->admin));
        $this->assertTrue($created->subject->is($provider));
        $this->assertSame('audited-openai', $created->properties['slug']);
        $this->assertStringNotContainsString('sk-audited-key', json_encode($created->properties));

        Livewire::test(EditAiProvider::class, ['record' => $provider->getRouteKey()])
            ->fillForm([
                'name' => 'Auditado Editado',
                'slug' => 'audited-openai',
                'ai_provider_driver_id' => $provider->ai_provider_driver_id,
                'api_key' => 'sk-rotated-key',
                'base_url' => null,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $updated = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::PROVIDER_UPDATED))->firstOrFail();
        $this->assertTrue($updated->subject->is($provider));
        $this->assertContains('api_key', $updated->properties['changed']);
        $this->assertArrayNotHasKey('api_key', $updated->properties['changes']);
        $this->assertStringNotContainsString('sk-rotated-key', json_encode($updated->properties));

        Livewire::test(EditAiProvider::class, ['record' => $provider->getRouteKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $deleted = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::PROVIDER_DELETED))->firstOrFail();
        $this->assertTrue($deleted->subject->is($provider));
        $this->assertSoftDeleted($provider);
    }
}
