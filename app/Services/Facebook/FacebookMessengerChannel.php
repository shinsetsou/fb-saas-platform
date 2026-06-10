<?php

namespace App\Services\Facebook;

use App\Models\Conversation;
use App\Services\Channels\ConversationChannel;

class FacebookMessengerChannel implements ConversationChannel
{
    public function __construct(private FacebookGraph $graph) {}

    public function sendReply(Conversation $conversation, string $text): void
    {
        if ($text === '') {
            return;
        }

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $text,
        ]);

        $page = $conversation->workspace->facebookPage;

        if ($page === null) {
            return;
        }

        $this->graph->sendMessage(
            $page->page_id,
            $page->page_access_token,
            $conversation->external_participant_id,
            $text,
        );
    }
}
