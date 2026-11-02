<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ConversationModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_deleting_conversation_soft_deletes_and_keeps_messages_until_force_delete(): void
    {
        $conversation = Conversation::factory()->create();
        $messages = Message::factory()->count(2)->for($conversation)->create();

        $conversation->delete();

        $this->assertSoftDeleted($conversation);
        $this->assertNull(Conversation::query()->find($conversation->id));
        $this->assertNotNull(Conversation::withTrashed()->find($conversation->id));
        $this->assertSame(2, Message::query()->where('conversation_id', $conversation->id)->count());

        $conversation->forceDelete();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertSame(0, Message::query()->whereIn('id', $messages->modelKeys())->count());
    }

    public function test_recent_scope_and_touch_activity(): void
    {
        $user = User::factory()->create();
        $old = Conversation::factory()->for($user)->create(['last_activity_at' => now()->subDays(2)]);
        $new = Conversation::factory()->for($user)->create(['last_activity_at' => now()->subDay()]);

        $this->assertSame([$new->id, $old->id], Conversation::recent()->pluck('id')->all());

        $this->travel(5)->minutes();
        $old->touchActivity();

        $this->assertSame([$old->id, $new->id], Conversation::recent()->pluck('id')->all());
        $this->assertTrue($user->conversations->contains($old));
    }

    public function test_message_scopes_relations_and_streaming_state(): void
    {
        $conversation = Conversation::factory()->create();
        $userMessage = Message::factory()->for($conversation)->voice()->create();
        $reply = Message::factory()->for($conversation)->streaming()->create();

        $this->assertSame([$userMessage->id], Message::fromUser()->pluck('id')->all());
        $this->assertSame([$reply->id], Message::fromAssistant()->pluck('id')->all());
        $this->assertTrue($reply->isStreaming());
        $this->assertFalse($userMessage->isStreaming());
        $this->assertSame('voice', $userMessage->source->slug);
        $this->assertSame(ExecutionStatus::RUNNING, $reply->status->slug);
        $this->assertTrue($reply->user->is($conversation->user));

        $reply->update(['payload' => ['rows' => [1, 2]], 'suggested_actions' => [['label' => 'Ok', 'request' => 'ok']]]);
        $this->assertSame(['rows' => [1, 2]], $reply->fresh()->payload);
    }
}
