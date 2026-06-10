<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Facebook\FacebookGraph;
use App\Services\Facebook\FacebookMessengerChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFacebookGraph;
use Tests\TestCase;

class FacebookMessengerChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_reply_persists_message_and_calls_graph(): void
    {
        $fake = new FakeFacebookGraph;
        $this->app->instance(FacebookGraph::class, $fake);

        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->facebookPage()->create([
            'page_id' => '999',
            'page_name' => 'Shop',
            'page_access_token' => 'page-tok',
            'subscribed_at' => now(),
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'facebook',
            'external_participant_id' => 'psid-7',
            'status' => 'active',
        ]);

        $message = app(FacebookMessengerChannel::class)->sendReply($conversation, 'Booked!');

        $this->assertNotNull($message);
        $this->assertSame('Booked!', $message->content);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Booked!',
        ]);

        $this->assertCount(1, $fake->sent);
        $this->assertSame([
            'page_id' => '999',
            'page_token' => 'page-tok',
            'recipient_id' => 'psid-7',
            'text' => 'Booked!',
        ], $fake->sent[0]);
    }

    public function test_send_reply_skips_empty_text(): void
    {
        $fake = new FakeFacebookGraph;
        $this->app->instance(FacebookGraph::class, $fake);

        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->facebookPage()->create([
            'page_id' => '999',
            'page_name' => 'Shop',
            'page_access_token' => 'page-tok',
            'subscribed_at' => now(),
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'facebook',
            'external_participant_id' => 'psid-7',
            'status' => 'active',
        ]);

        app(FacebookMessengerChannel::class)->sendReply($conversation, '');

        $this->assertDatabaseCount('messages', 0);
        $this->assertCount(0, $fake->sent);
    }
}
