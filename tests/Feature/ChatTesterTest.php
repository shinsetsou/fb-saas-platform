<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Bot\Claude\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class ChatTesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_a_message_and_see_reply(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Hello from the bot.'],
            ]],
        ]));

        $this->actingAs($user)
            ->post('/chat-tester', ['message' => 'hi'])
            ->assertRedirect('/chat-tester');

        $this->actingAs($user)
            ->get('/chat-tester')
            ->assertOk()
            ->assertSee('Hello from the bot.');
    }

    public function test_page_requires_auth(): void
    {
        $this->get('/chat-tester')->assertRedirect('/login');
    }
}
