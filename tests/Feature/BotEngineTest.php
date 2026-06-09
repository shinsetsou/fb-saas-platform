<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Booking\Google\GoogleCalendar;
use App\Services\Bot\BotEngine;
use App\Services\Bot\Claude\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class BotEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GoogleCalendar::class, new FakeGoogleCalendar());
    }

    private function conversation(): Conversation
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);
        $workspace->googleAccount()->create([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
        ]);

        return Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);
    }

    public function test_engine_runs_tool_then_returns_text(): void
    {
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'book_appointment', 'input' => [
                    'starts_at' => '2026-06-15T09:00:00+00:00',
                    'customer_name' => 'Sam',
                    'customer_contact' => 'sam@example.com',
                ]],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => "You're booked for Monday at 9am."],
            ]],
        ]));

        $conversation = $this->conversation();
        $reply = app(BotEngine::class)->handle($conversation, 'Book me Monday 9am, I am Sam, sam@example.com');

        $this->assertSame("You're booked for Monday at 9am.", $reply);
        $this->assertDatabaseHas('appointments', ['customer_contact' => 'sam@example.com']);
        // user message + tool result message both persisted (assistant reply is persisted by the channel).
        $this->assertGreaterThanOrEqual(2, $conversation->messages()->count());
    }

    public function test_disabled_bot_does_not_reply(): void
    {
        $conversation = $this->conversation();
        $conversation->workspace->botSetting->update(['is_enabled' => false]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([]));

        $reply = app(BotEngine::class)->handle($conversation->fresh(), 'hello?');

        $this->assertSame('', $reply);
    }
}
