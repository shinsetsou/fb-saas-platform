<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Billing\UsageRecorder;
use App\Services\Bot\BotEngine;
use App\Services\Bot\Claude\ClaudeClient;
use App\Services\Channels\ChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class ProcessIncomingMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_conversation_and_persists_reply(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Hi! How can I help?'],
            ]],
        ]));

        (new ProcessIncomingMessage($workspace->id, 'web', 'visitor-1', 'hello'))
            ->handle(app(BotEngine::class), app(ChannelResolver::class), app(UsageRecorder::class));

        $conversation = Conversation::where('external_participant_id', 'visitor-1')->firstOrFail();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hi! How can I help?',
        ]);
    }
}
