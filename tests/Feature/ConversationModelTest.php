<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Actions\Workspaces\CreateWorkspaceForUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_has_messages_and_appointments(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester-1',
            'status' => 'active',
        ]);

        $conversation->messages()->create(['role' => 'user', 'content' => 'hi']);
        $conversation->appointments()->create([
            'workspace_id' => $workspace->id,
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'booked',
        ]);

        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(1, $conversation->appointments()->count());
        $this->assertSame('web', $conversation->channel);
    }
}
