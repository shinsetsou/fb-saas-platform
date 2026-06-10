<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Channels\WebChatChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebChatChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_reply_returns_persisted_message(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);

        $message = app(WebChatChannel::class)->sendReply($conversation, 'Hello there.');

        $this->assertNotNull($message);
        $this->assertSame('assistant', $message->role);
        $this->assertSame('Hello there.', $message->content);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_send_reply_returns_null_on_empty_text(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);

        $this->assertNull(app(WebChatChannel::class)->sendReply($conversation, ''));
        $this->assertDatabaseCount('messages', 0);
    }
}
