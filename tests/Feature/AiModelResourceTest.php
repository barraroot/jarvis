<?php

namespace Tests\Feature;

use App\Filament\Resources\AiModels\Pages\CreateAiModel;
use App\Filament\Resources\AiModels\Pages\EditAiModel;
use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Models\AiModel;
use App\Models\AiModelCapability;
use App\Models\AiProvider;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AiModelResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->setUpSeedsLookups();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);

        $this->provider = AiProvider::factory()->create();
    }

    public function test_setting_new_default_text_unsets_previous(): void
    {
        $previous = AiModel::factory()->defaultText()->for($this->provider, 'provider')->create();

        Livewire::test(CreateAiModel::class)
            ->fillForm([
                'ai_provider_id' => $this->provider->id,
                'name' => 'Novo padrão',
                'model_identifier' => 'gpt-new-default',
                'context_window' => 128000,
                'input_cost_per_million' => 5,
                'output_cost_per_million' => 15,
                'capabilities' => [AiModelCapability::idFor(AiModelCapability::TEXT)],
                'is_default_text' => true,
                'is_default_embedding' => false,
                'fallback_order' => 1,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($previous->fresh()->is_default_text);

        $created = AiModel::query()->where('model_identifier', 'gpt-new-default')->firstOrFail();
        $this->assertTrue($created->is_default_text);
        $this->assertSame(1, $created->fallback_order);
        $this->assertTrue($created->hasCapability(AiModelCapability::TEXT));

        // The new default took over from the previous one.
        $this->assertSame(1, AiModel::query()->where('is_default_text', true)->count());
    }

    public function test_reorder_updates_fallback_order(): void
    {
        $first = AiModel::factory()->fallback(1)->for($this->provider, 'provider')->create();
        $second = AiModel::factory()->fallback(2)->for($this->provider, 'provider')->create();
        $third = AiModel::factory()->fallback(3)->for($this->provider, 'provider')->create();

        Livewire::test(ListAiModels::class)
            ->call('reorderTable', [$third->id, $first->id, $second->id]);

        $this->assertSame(1, $third->fresh()->fallback_order);
        $this->assertSame(2, $first->fresh()->fallback_order);
        $this->assertSame(3, $second->fresh()->fallback_order);
    }

    public function test_model_without_text_capability_cannot_be_default_text(): void
    {
        Livewire::test(CreateAiModel::class)
            ->fillForm([
                'ai_provider_id' => $this->provider->id,
                'name' => 'Embedding disfarçado',
                'model_identifier' => 'embedding-xyz',
                'input_cost_per_million' => 1,
                'output_cost_per_million' => 2,
                'capabilities' => [AiModelCapability::idFor(AiModelCapability::EMBEDDINGS)],
                'is_default_text' => true,
                'is_default_embedding' => false,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['is_default_text']);

        $this->assertDatabaseMissing('ai_models', ['model_identifier' => 'embedding-xyz']);
    }

    public function test_model_changes_are_audited(): void
    {
        Livewire::test(CreateAiModel::class)
            ->fillForm([
                'ai_provider_id' => $this->provider->id,
                'name' => 'Auditado',
                'model_identifier' => 'gpt-audited',
                'input_cost_per_million' => 3,
                'output_cost_per_million' => 7,
                'capabilities' => [AiModelCapability::idFor(AiModelCapability::TEXT)],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $model = AiModel::query()->where('model_identifier', 'gpt-audited')->firstOrFail();

        $created = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::MODEL_CREATED))->firstOrFail();
        $this->assertTrue($created->user->is($this->admin));
        $this->assertTrue($created->subject->is($model));
        $this->assertSame('gpt-audited', $created->properties['model_identifier']);

        Livewire::test(EditAiModel::class, ['record' => $model->getRouteKey()])
            ->fillForm([
                'ai_provider_id' => $this->provider->id,
                'name' => 'Auditado Editado',
                'model_identifier' => 'gpt-audited',
                'input_cost_per_million' => 3,
                'output_cost_per_million' => 7,
                'capabilities' => [AiModelCapability::idFor(AiModelCapability::TEXT)],
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $updated = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::MODEL_UPDATED))->firstOrFail();
        $this->assertTrue($updated->subject->is($model));
        $this->assertContains('name', $updated->properties['changed']);

        Livewire::test(EditAiModel::class, ['record' => $model->getRouteKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $deleted = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::MODEL_DELETED))->firstOrFail();
        $this->assertTrue($deleted->subject->is($model));
        $this->assertSoftDeleted($model);
    }
}
