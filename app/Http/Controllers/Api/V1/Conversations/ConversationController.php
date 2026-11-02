<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conversations\UpdateConversationModelRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;

/**
 * Updates a conversation's settings; today: the AI model used for its messages (US-3.5).
 */
class ConversationController extends Controller
{
    public function update(UpdateConversationModelRequest $request, Conversation $conversation): ConversationResource
    {
        $conversation->update($request->validated());

        $conversation->touchActivity();

        return ConversationResource::make($conversation->load('model.provider'));
    }
}
