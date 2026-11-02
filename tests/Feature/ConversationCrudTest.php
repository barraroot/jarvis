<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ConversationCrudTest extends TestCase
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

    public function test_lists_only_own_conversations_paginated_by_20(): void
    {
        $other = User::factory()->create();

        $newest = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Mais recente',
            'last_activity_at' => now(),
        ]);

        Conversation::factory()->count(19)->create([
            'user_id' => $this->user->id,
            'last_activity_at' => now()->subMinutes(5),
        ]);

        $oldest = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Mais antiga',
            'last_activity_at' => now()->subMinutes(10),
        ]);

        Conversation::factory()->create(['user_id' => $other->id, 'title' => 'Conversa alheia']);

        $response = $this->getJson('/api/v1/conversations');

        $response->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $newest->id);

        $this->assertStringNotContainsString('Conversa alheia', $response->getContent());

        $this->getJson('/api/v1/conversations?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $oldest->id);
    }

    public function test_store_creates_conversation_with_provisional_title(): void
    {
        $this->postJson('/api/v1/conversations')
            ->assertCreated()
            ->assertJsonPath('data.title', Conversation::PROVISIONAL_TITLE)
            ->assertJsonPath('data.ai_model_id', null);

        $this->assertDatabaseHas('conversations', [
            'user_id' => $this->user->id,
            'title' => Conversation::PROVISIONAL_TITLE,
        ]);
    }

    public function test_show_returns_last_50_messages_with_cursor(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        Message::factory()->count(55)->for($conversation, 'conversation')->create();

        $ids = Message::query()->orderBy('id')->pluck('id');

        $response = $this->getJson('/api/v1/conversations/'.$conversation->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonCount(50, 'messages');

        $messageIds = collect($response->json('messages'))->pluck('id');
        $this->assertSame($ids->last(), $messageIds->first());
        $this->assertSame($ids->get(5), $messageIds->last());
        $this->assertSame($ids->get(5), $response->json('next_cursor'));

        $older = $this->getJson('/api/v1/conversations/'.$conversation->id.'?before='.$ids->get(5));

        $older->assertOk()
            ->assertJsonCount(5, 'messages')
            ->assertJsonPath('next_cursor', null);

        $olderIds = collect($older->json('messages'))->pluck('id');
        $this->assertSame($ids->get(4), $olderIds->first());
        $this->assertSame($ids->first(), $olderIds->last());
    }

    public function test_rename_validates_length(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $this->patchJson('/api/v1/conversations/'.$conversation->id, ['title' => str_repeat('a', 121)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

        $this->patchJson('/api/v1/conversations/'.$conversation->id, ['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

        $this->patchJson('/api/v1/conversations/'.$conversation->id, ['title' => 'Minha conversa'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Minha conversa');

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'title' => 'Minha conversa']);

        $this->assertSame(['title_edited' => true], $conversation->fresh()->meta);
    }

    public function test_delete_soft_deletes(): void
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        Message::factory()->for($conversation, 'conversation')->create();

        $this->deleteJson('/api/v1/conversations/'.$conversation->id)->assertOk();

        $this->assertSoftDeleted('conversations', ['id' => $conversation->id]);

        $this->getJson('/api/v1/conversations/'.$conversation->id)->assertNotFound();
        $this->getJson('/api/v1/conversations')->assertJsonCount(0, 'data');

        // Soft delete keeps the messages intact.
        $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_cannot_access_others_conversation(): void
    {
        $intruder = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $intruder->id]);

        $originalTitle = $conversation->fresh()->title;

        $this->getJson('/api/v1/conversations/'.$conversation->id)->assertForbidden();
        $this->patchJson('/api/v1/conversations/'.$conversation->id, ['title' => 'Roubada'])->assertForbidden();
        $this->deleteJson('/api/v1/conversations/'.$conversation->id)->assertForbidden();

        $this->assertSame($originalTitle, $conversation->fresh()->title);
    }
}
