<?php

namespace Tests\Feature;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Resources\Conversations\RelationManagers\MessagesRelationManager;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ConversationResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_can_view_any_conversation_read_only(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Operadora']);
        $bob = User::factory()->create(['name' => 'Bob Operador']);

        $aliceConversation = Conversation::factory()->create([
            'user_id' => $alice->id,
            'title' => 'Conversa da Alice',
            'meta' => ['title_edited' => true],
        ]);

        $bobConversation = Conversation::factory()->create([
            'user_id' => $bob->id,
            'title' => 'Conversa do Bob',
        ]);

        Message::factory()->for($aliceConversation, 'conversation')->create(['content' => 'Mensagem da conversa da Alice']);

        // The list shows conversations from every user.
        $this->get('/admin/conversations')
            ->assertOk()
            ->assertSee('Conversa da Alice')
            ->assertSee('Conversa do Bob');

        // Filter by user narrows the table.
        Livewire::test(ListConversations::class)
            ->assertCanSeeTableRecords([$aliceConversation, $bobConversation])
            ->filterTable('user', $alice->id)
            ->assertCanSeeTableRecords([$aliceConversation])
            ->assertCanNotSeeTableRecords([$bobConversation]);

        // The view page shows the conversation's details; messages come from
        // the read-only relation manager.
        $this->get('/admin/conversations/'.$aliceConversation->id)
            ->assertOk()
            ->assertSee('Conversa da Alice')
            ->assertSee('Alice Operadora');

        $message = Message::query()->firstOrFail();

        Livewire::test(MessagesRelationManager::class, [
            'ownerRecord' => $aliceConversation,
            'pageClass' => ViewConversation::class,
        ])->assertCanSeeTableRecords([$message]);

        // Read-only: no edit page, no create page.
        $this->assertFalse(ConversationResource::hasPage('create'));
        $this->assertFalse(ConversationResource::hasPage('edit'));
        $this->get('/admin/conversations/'.$aliceConversation->id.'/edit')->assertNotFound();

        // Operators cannot open the admin conversation list.
        $this->actingAs($alice)->get('/admin/conversations')->assertForbidden();
    }
}
