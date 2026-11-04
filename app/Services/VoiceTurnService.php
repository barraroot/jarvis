<?php

namespace App\Services;

use App\Jobs\ExtractMemoryHypotheses;
use App\Models\ExecutionStatus;
use App\Models\Message;
use App\Models\MessageRole;
use App\Models\MessageSource;
use App\Models\VoiceSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persists the transcribed turns of a Realtime session as `messages` with
 * `source = voice` in the session's conversation (CT-10, RF-21).
 *
 * The batch is inserted in a single statement (constant query count, RNF-05),
 * in the order sent. Memory hypotheses are extracted from every assistant turn
 * on the `low` queue once the transaction commits.
 */
class VoiceTurnService
{
    /**
     * @param  list<array{role: string, content: string, spoken_at: string}>  $turns
     * @return Collection<int, Message>
     */
    public function store(VoiceSession $voiceSession, array $turns): Collection
    {
        $messages = DB::transaction(function () use ($voiceSession, $turns): Collection {
            $now = now();

            $rows = array_map(fn (array $turn): array => [
                'conversation_id' => $voiceSession->conversation_id,
                'user_id' => $voiceSession->user_id,
                'message_role_id' => MessageRole::idFor($turn['role']),
                'message_source_id' => MessageSource::idFor(MessageSource::VOICE),
                'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
                'ai_model_id' => $turn['role'] === MessageRole::ASSISTANT ? $voiceSession->ai_model_id : null,
                'content' => $turn['content'],
                'meta' => json_encode(['spoken_at' => Carbon::parse($turn['spoken_at'])->toIso8601String()]),
                'created_at' => $now,
                'updated_at' => $now,
            ], $turns);

            $ids = Message::query()->insertOrIgnoreReturning($rows, ['id'])->pluck('id');

            DB::table('conversations')->where('id', $voiceSession->conversation_id)->update([
                'last_activity_at' => $now,
                'updated_at' => $now,
            ]);

            $voiceSession->touchActivity();

            // Sequence ids follow the VALUES order, so id order is the batch order.
            return Message::query()
                ->with(['role', 'source', 'status'])
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get();
        });

        $messages
            ->filter(fn (Message $message): bool => $message->role->slug === MessageRole::ASSISTANT)
            ->each(fn (Message $message) => ExtractMemoryHypotheses::dispatch($message)->onConnection('redis')->onQueue('low'));

        return $messages;
    }
}
