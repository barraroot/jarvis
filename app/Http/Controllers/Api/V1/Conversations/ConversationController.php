<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conversations\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD of the operator's conversations (US-2.6): list, create, open (with a
 * backward message cursor), rename / switch model, soft delete.
 */
class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Conversation::class);

        $conversations = Conversation::query()
            ->with('model.provider')
            ->where('user_id', $request->user()->getKey())
            ->recent()
            ->paginate(20);

        return ConversationResource::collection($conversations);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Conversation::class);

        $conversation = $request->user()->conversations()->create([
            'title' => Conversation::PROVISIONAL_TITLE,
            'last_activity_at' => now(),
        ]);

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The conversation plus its latest 50 messages (newest first). Pass
     * `?before=<message_id>` to page backwards through older messages (US-2.6).
     */
    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        $messagesQuery = $conversation->messages()->latest('id')->limit(50);

        if ($request->filled('before')) {
            $messagesQuery->where('id', '<', (int) $request->integer('before'));
        }

        $messages = $messagesQuery->get();

        return ConversationResource::make($conversation->load('model.provider'))
            ->additional([
                'messages' => MessageResource::collection($messages),
                // The oldest id of this page; the client uses it as the next `before` cursor.
                'next_cursor' => $messages->count() >= 50 ? $messages->last()->getKey() : null,
            ]);
    }

    /**
     * Renames the conversation (US-2.6) and/or switches its AI model (US-3.5).
     */
    public function update(UpdateConversationRequest $request, Conversation $conversation): ConversationResource
    {
        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $data['meta'] = array_merge($conversation->meta ?? [], ['title_edited' => true]);
        }

        $conversation->update($data);

        $conversation->touchActivity();

        return ConversationResource::make($conversation->load('model.provider'));
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
