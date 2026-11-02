<?php

namespace Tests\Feature;

use App\Filament\Resources\Memories\MemoryResource;
use App\Filament\Resources\Memories\Pages\ListMemories;
use App\Models\AiModel;
use App\Models\AuditAction;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class MemoryResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);

        AiModel::factory()->defaultEmbedding()->create();
    }

    public function test_admin_delete_is_audited_and_removes_embedding(): void
    {
        $operator = User::factory()->create(['name' => 'Alice Operadora']);

        $memory = Memory::factory()->confirmed()->for($operator)->create([
            'content' => 'Memória que será removida pelo admin',
        ]);

        $embedding = MemoryEmbedding::factory()
            ->for($memory)
            ->for(AiModel::query()->firstOrFail(), 'model')
            ->vector([1.0, 0.0, 0.0])
            ->create();

        // The list shows memories from every user, with content and origin visible.
        $this->get('/admin/memories')
            ->assertOk()
            ->assertSee('Memória que será removida pelo admin')
            ->assertSee('Alice Operadora');

        Livewire::test(ListMemories::class)
            ->assertCanSeeTableRecords([$memory]);

        // Deleting through the table action runs the service: hard delete + audit.
        Livewire::test(ListMemories::class)
            ->callTableAction(DeleteAction::class, $memory);

        $this->assertDatabaseMissing('memories', ['id' => $memory->id]);
        $this->assertDatabaseMissing('memory_embeddings', ['id' => $embedding->id]);

        $this->assertDatabaseHas('audit_logs', [
            'audit_action_id' => AuditAction::idFor('memory_deleted'),
            'user_id' => $this->admin->id,
            'subject_type' => (new Memory)->getMorphClass(),
            'subject_id' => $memory->id,
        ]);

        // Read-only: no edit or create page for memories.
        $this->assertFalse(MemoryResource::hasPage('create'));
        $this->assertFalse(MemoryResource::hasPage('edit'));
        $this->get('/admin/memories/'.$memory->id.'/edit')->assertNotFound();

        // Operators cannot open the admin memory list.
        $this->actingAs($operator)->get('/admin/memories')->assertForbidden();
    }
}
