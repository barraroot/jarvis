<?php

namespace Tests\Feature;

use App\Exceptions\NoModelAvailableException;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiProviderDriver;
use App\Models\Conversation;
use App\Services\ModelSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ModelSelectorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->provider = AiProvider::factory()->create();
    }

    public function test_conversation_model_wins_over_agent_and_default(): void
    {
        $default = AiModel::factory()->defaultText()->for($this->provider, 'provider')->create();
        $agentModel = AiModel::factory()->for($this->provider, 'provider')->create();
        $agent = Agent::factory()->create(['ai_model_id' => $agentModel->id]);
        $conversationModel = AiModel::factory()->for($this->provider, 'provider')->create();
        $conversation = Conversation::factory()->create(['ai_model_id' => $conversationModel->id]);

        $selected = app(ModelSelector::class)->forConversation($conversation, $agent);

        $this->assertTrue($selected->is($conversationModel));
        $this->assertFalse($selected->is($default));
        $this->assertFalse($selected->is($agentModel));
    }

    public function test_inactive_conversation_model_is_skipped(): void
    {
        $default = AiModel::factory()->defaultText()->for($this->provider, 'provider')->create();
        $agentModel = AiModel::factory()->for($this->provider, 'provider')->create();
        $agent = Agent::factory()->create(['ai_model_id' => $agentModel->id]);
        $inactiveConversationModel = AiModel::factory()->inactive()->for($this->provider, 'provider')->create();
        $conversation = Conversation::factory()->create(['ai_model_id' => $inactiveConversationModel->id]);

        $selected = app(ModelSelector::class)->forConversation($conversation, $agent);

        $this->assertTrue($selected->is($agentModel));

        // Without an agent model the global default applies.
        $plainAgent = Agent::factory()->create(['ai_model_id' => null]);

        $selected = app(ModelSelector::class)->forConversation($conversation, $plainAgent);

        $this->assertTrue($selected->is($default));
    }

    public function test_throws_when_no_active_text_model(): void
    {
        $conversation = Conversation::factory()->create();

        $this->expectException(NoModelAvailableException::class);

        app(ModelSelector::class)->forConversation($conversation);
    }

    public function test_embedding_model_returns_active_default_embedding(): void
    {
        $embedding = AiModel::factory()->defaultEmbedding()->for($this->provider, 'provider')->create();
        AiModel::factory()->embeddings()->for($this->provider, 'provider')->create();

        $selected = app(ModelSelector::class)->embeddingModel();

        $this->assertTrue($selected->is($embedding));
    }

    public function test_default_realtime_model_ignores_inactive_provider(): void
    {
        $realtime = AiModel::factory()->defaultRealtime()->for($this->provider, 'provider')->create();

        $selected = app(ModelSelector::class)->defaultRealtimeModel();

        $this->assertNotNull($selected);
        $this->assertTrue($selected->is($realtime));
        $this->assertTrue($selected->relationLoaded('provider'));
        $this->assertTrue($selected->provider->relationLoaded('driver'));

        $this->provider->update(['is_active' => false]);

        $this->assertNull(app(ModelSelector::class)->defaultRealtimeModel());
    }

    public function test_default_realtime_model_requires_openai_driver_active_model_and_capability(): void
    {
        $anthropic = AiProvider::factory()->driver(AiProviderDriver::ANTHROPIC)->create();
        AiModel::factory()->defaultRealtime()->for($anthropic, 'provider')->create();

        $this->assertNull(app(ModelSelector::class)->defaultRealtimeModel());

        AiModel::query()->update(['is_default_realtime' => false]);
        AiModel::factory()->defaultRealtime()->inactive()->for($this->provider, 'provider')->create();

        $this->assertNull(app(ModelSelector::class)->defaultRealtimeModel());

        AiModel::query()->update(['is_default_realtime' => false]);
        AiModel::factory()->defaultText()->for($this->provider, 'provider')->create(['is_default_realtime' => true]);

        $this->assertNull(app(ModelSelector::class)->defaultRealtimeModel());
    }
}
