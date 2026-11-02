<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ConversationModelSwitchTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();
        $this->provider = AiProvider::factory()->create();
    }

    public function test_user_can_switch_to_active_model(): void
    {
        $model = AiModel::factory()->for($this->provider, 'provider')->create();
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->patchJson(
            '/api/v1/conversations/'.$conversation->id,
            ['ai_model_id' => $model->id],
        );

        $response->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.ai_model_id', $model->id)
            ->assertJsonPath('data.model.id', $model->id)
            ->assertJsonPath('data.model.name', $model->name)
            ->assertJsonMissingPath('data.model.provider_key');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'ai_model_id' => $model->id,
        ]);
    }

    public function test_inactive_model_is_rejected(): void
    {
        $inactive = AiModel::factory()->inactive()->for($this->provider, 'provider')->create();
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->patchJson(
            '/api/v1/conversations/'.$conversation->id,
            ['ai_model_id' => $inactive->id],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ai_model_id']);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'ai_model_id' => null,
        ]);
    }

    public function test_other_users_conversation_is_forbidden(): void
    {
        $model = AiModel::factory()->for($this->provider, 'provider')->create();
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->patchJson(
            '/api/v1/conversations/'.$conversation->id,
            ['ai_model_id' => $model->id],
        )->assertForbidden();

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'ai_model_id' => null,
        ]);
    }
}
