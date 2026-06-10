<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Jobs\ProcessIncomingMessage;
use App\Models\User;
use App\Services\Billing\UsageRecorder;
use App\Services\Bot\BotEngine;
use App\Services\Bot\Claude\ClaudeClient;
use App\Services\Channels\ChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class ProcessIncomingMessageUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_empty_reply_records_one_usage_row(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Hello!']]],
        ]));

        (new ProcessIncomingMessage($workspace->id, 'web', 'visitor-1', 'hi'))
            ->handle(app(BotEngine::class), app(ChannelResolver::class), app(UsageRecorder::class));

        $this->assertDatabaseCount('usage_records', 1);
    }

    public function test_disabled_bot_empty_reply_records_no_usage(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        // botSetting->is_enabled defaults to false → BotEngine returns '' → no reply

        (new ProcessIncomingMessage($workspace->id, 'web', 'visitor-1', 'hi'))
            ->handle(app(BotEngine::class), app(ChannelResolver::class), app(UsageRecorder::class));

        $this->assertDatabaseCount('usage_records', 0);
    }
}
