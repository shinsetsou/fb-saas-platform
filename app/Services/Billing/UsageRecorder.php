<?php

namespace App\Services\Billing;

use App\Models\Message;
use App\Models\UsageRecord;
use Throwable;

class UsageRecorder
{
    public function __construct(private UsageMeter $meter) {}

    public function record(Message $message): void
    {
        $conversation = $message->conversation;
        $workspace = $conversation->workspace;

        $record = UsageRecord::firstOrCreate(
            ['message_id' => $message->id],
            ['workspace_id' => $workspace->id, 'channel' => $conversation->channel],
        );

        if (! $record->wasRecentlyCreated) {
            return;
        }

        if (! $workspace->subscribed('default')) {
            return;
        }

        try {
            $this->meter->report($workspace);
            $record->update(['reported_at' => now()]);
        } catch (Throwable $e) {
            report($e); // leave reported_at null for replay
        }
    }
}
