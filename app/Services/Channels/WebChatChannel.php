<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;

class WebChatChannel implements ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): ?Message
    {
        if ($text === '') {
            return null;
        }

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $text,
        ]);
    }
}
