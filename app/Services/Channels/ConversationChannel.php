<?php

namespace App\Services\Channels;

use App\Models\Conversation;

interface ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): void;
}
