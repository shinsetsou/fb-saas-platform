<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;

interface ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): ?Message;
}
