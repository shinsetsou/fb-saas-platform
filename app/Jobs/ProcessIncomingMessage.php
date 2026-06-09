<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\Bot\BotEngine;
use App\Services\Channels\ConversationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $workspaceId,
        public string $channel,
        public string $participantId,
        public string $text,
    ) {}

    public function handle(BotEngine $engine, ConversationChannel $channel): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'channel' => $this->channel,
                'external_participant_id' => $this->participantId,
            ],
            ['status' => 'active'],
        );

        $reply = $engine->handle($conversation, $this->text);

        $channel->sendReply($conversation, $reply);
    }
}
