<?php

namespace App\Services\Facebook;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\ConversationChannel;

class FacebookMessengerChannel implements ConversationChannel
{
    public function __construct(private FacebookGraph $graph) {}

    public function sendReply(Conversation $conversation, string $text): ?Message
    {
        if ($text === '') {
            return null;
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $text,
        ]);

        $page = $conversation->workspace->facebookPage;

        if ($page !== null) {
            $this->graph->sendMessage(
                $page->page_id,
                $page->page_access_token,
                $conversation->external_participant_id,
                $text,
            );
        }

        return $message;
    }
}
