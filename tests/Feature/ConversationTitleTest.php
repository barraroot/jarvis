<?php

namespace Tests\Feature;

use App\Jobs\GenerateConversationTitle;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ConversationTitleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->user = User::factory()->create();

        $provider = AiProvider::factory()->create();
        AiModel::factory()->defaultText()->for($provider, 'provider')->create();
    }

    public function test_auto_title_does_not_override_manual_title(): void
    {
        // Even with a provisional-looking title, the manual-edit flag protects it.
        $manual = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'title' => Conversation::PROVISIONAL_TITLE,
            'meta' => ['title_edited' => true],
        ]);

        Message::factory()->for($manual, 'conversation')->create(['content' => 'Primeira mensagem']);

        Ai::fakeAgent(AnonymousAgent::class, fn (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse => new TextResponse('Título automático', new TextUsage(10, 5), new Meta($provider->name(), $model)));

        (new GenerateConversationTitle($manual))->handle();

        $this->assertSame(Conversation::PROVISIONAL_TITLE, $manual->fresh()->title);

        // A provisional title is replaced by the generated one, via the default model.
        $provisional = Conversation::factory()->create(['user_id' => $this->user->id, 'title' => Conversation::PROVISIONAL_TITLE]);

        Message::factory()->for($provisional, 'conversation')->create(['content' => 'Resuma minhas tarefas de hoje']);

        (new GenerateConversationTitle($provisional))->handle();

        $this->assertSame('Título automático', $provisional->fresh()->title);

        // Generated titles never exceed 60 characters.
        $limited = Conversation::factory()->create(['user_id' => $this->user->id, 'title' => Conversation::PROVISIONAL_TITLE]);
        Message::factory()->for($limited, 'conversation')->create(['content' => 'Mensagem inicial']);

        Ai::fakeAgent(AnonymousAgent::class, fn (string $prompt, mixed $attachments, mixed $provider, string $model): TextResponse => new TextResponse(str_repeat('a', 80), new TextUsage(10, 5), new Meta($provider->name(), $model)));

        (new GenerateConversationTitle($limited))->handle();

        $this->assertSame(str_repeat('a', 60), $limited->fresh()->title);
    }
}
