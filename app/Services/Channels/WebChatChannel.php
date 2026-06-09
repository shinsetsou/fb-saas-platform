<?php

namespace App\Services\Channels;

use App\Models\Conversation;

class WebChatChannel implements ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): void
    {
        if ($text === '') {
            return;
        }

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $text,
        ]);
    }
}
