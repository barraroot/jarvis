<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AvailableModelsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_lists_only_active_text_models(): void
    {
        $provider = AiProvider::factory()->create(['api_key' => 'sk-never-leak-this-key-0000']);
        $inactiveProvider = AiProvider::factory()->inactive()->create();

        $defaultText = AiModel::factory()->defaultText()->for($provider, 'provider')->create(['name' => 'Default Text']);
        $activeText = AiModel::factory()->for($provider, 'provider')->create(['name' => 'Active Text']);
        AiModel::factory()->inactive()->for($provider, 'provider')->create(['name' => 'Inactive Text']);
        AiModel::factory()->for($inactiveProvider, 'provider')->create(['name' => 'Inactive Provider']);
        AiModel::factory()->embeddings()->for($provider, 'provider')->create(['name' => 'Embeddings Only']);

        $response = $this->getJson('/api/v1/ai/models');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $defaultText->id)
            ->assertJsonPath('data.0.name', 'Default Text')
            ->assertJsonPath('data.0.provider', $provider->slug)
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.id', $activeText->id)
            ->assertJsonPath('data.1.is_default', false);

        // API keys never reach API responses (US-5.1).
        $this->assertStringNotContainsString($provider->fresh()->api_key, $response->getContent());
        $response->assertJsonMissingPath('data.0.api_key');
        $response->assertJsonMissingPath('data.0.provider_key');
    }
}
