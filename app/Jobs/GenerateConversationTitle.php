<?php

namespace App\Jobs;

use App\Events\Conversation\ConversationTitleChanged;
use App\Jobs\Middleware\WithLogContext;
use App\Models\Conversation;
use App\Services\BrainClient;
use App\Services\BrainRequest;
use App\Services\ModelSelector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Replaces a conversation's provisional title with one generated from its first
 * user message (US-2.6). Manually edited titles (`meta.title_edited`) are never
 * overwritten.
 */
class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum length of a generated title.
     */
    public const int MAX_TITLE_LENGTH = 60;

    /**
     * Title generation also performs an AI call: no retries (no AI re-billing), 120 s timeout.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly Conversation $conversation) {}

    /**
     * Log lines of this job carry the operator and conversation.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithLogContext([
            'user_id' => $this->conversation->user_id,
            'conversation_id' => $this->conversation->id,
        ])];
    }

    public function handle(): void
    {
        $conversation = Conversation::query()->withTrashed()->find($this->conversation->getKey());

        if ($conversation === null || $conversation->trashed()) {
            return;
        }

        if (! $conversation->hasProvisionalTitle() || $conversation->titleWasEdited()) {
            return;
        }

        $firstMessage = $conversation->messages()->fromUser()->oldest('id')->first();

        if ($firstMessage === null) {
            return;
        }

        $user = $conversation->user;

        if ($user === null) {
            return;
        }

        try {
            $response = app(BrainClient::class)->stream(
                new BrainRequest(
                    user: $user,
                    primaryModel: app(ModelSelector::class)->forConversation($conversation),
                    prompt: 'Escreva um título curto e objetivo, com no máximo '.self::MAX_TITLE_LENGTH.' caracteres, para uma conversa cuja primeira mensagem é: '.$firstMessage->content,
                    instructions: 'You are JARVIS. Reply with a single short conversation title only, without quotes and without trailing punctuation.',
                ),
                static fn (string $delta): null => null,
            );

            $title = $this->normalizeTitle($response->text);

            if ($title === '') {
                return;
            }

            $conversation->update(['title' => $title]);

            event(new ConversationTitleChanged($user, $conversation));
        } catch (Throwable $exception) {
            // The provisional title stays; a later reply retries. No retries on the queue (AI billing).
            report($exception);
        }
    }

    /**
     * Strip decoration and enforce the maximum length.
     */
    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        $title = trim($title, "\"'“”‘’");

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }
}
