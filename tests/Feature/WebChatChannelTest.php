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

    public function test_send_reply_persists_assistant_message(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);

        app(WebChatChannel::class)->sendReply($conversation, 'Hello there.');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello there.',
        ]);
    }
}
