<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_replies_this_period_counts_this_month_usage_rows(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = $workspace->conversations()->create([
            'channel' => 'web',
            'external_participant_id' => 'tester-'.$user->id,
        ]);

        // One record inside the current month window, one outside it.
        $inMessage = $conversation->messages()->create(['role' => 'assistant', 'content' => 'fresh']);
        $workspace->usageRecords()->create(['message_id' => $inMessage->id, 'channel' => 'web']);

        $outMessage = $conversation->messages()->create(['role' => 'assistant', 'content' => 'old']);
        $out = new UsageRecord([
            'workspace_id' => $workspace->id,
            'message_id' => $outMessage->id,
            'channel' => 'web',
        ]);
        $out->created_at = now()->subMonths(2);
        $out->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['aria-label="replies this period"', '>1<'], false);

        $this->assertDatabaseCount('usage_records', 2);
    }

    public function test_recent_conversations_render_with_last_message_and_empty_state(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        // Empty state first.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No conversations yet');

        $conversation = $workspace->conversations()->create([
            'channel' => 'facebook',
            'external_participant_id' => 'ps-123',
            'status' => 'needs_human',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'I want a haircut Friday']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Facebook — ps-123')
            ->assertSee('Customer: I want a haircut Friday')
            ->assertSee('needs human');
    }

    public function test_recent_bookings_render_with_empty_state(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No bookings yet');

        $conversation = $workspace->conversations()->create([
            'channel' => 'facebook',
            'external_participant_id' => 'ps-777',
        ]);
        $workspace->appointments()->create([
            'conversation_id' => $conversation->id,
            'customer_name' => 'Ana Santos',
            'customer_contact' => 'ana@example.com',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(45),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Ana Santos')
            ->assertSee('ana@example.com');
    }

    public function test_participant_isolation_between_users_in_chat_tester(): void
    {
        $userA = User::factory()->create();
        $workspaceA = app(CreateWorkspaceForUser::class)->handle($userA);
        $workspaceA->botSetting->update(['is_enabled' => true]);

        $userB = User::factory()->create();
        $workspaceB = app(CreateWorkspaceForUser::class)->handle($userB);
        $workspaceB->botSetting->update(['is_enabled' => true]);

        $conversationA = $workspaceA->conversations()->create([
            'channel' => 'web',
            'external_participant_id' => 'tester-'.$userA->id,
        ]);
        $conversationA->messages()->create(['role' => 'assistant', 'content' => 'Salon A private reply']);

        $this->actingAs($userB)
            ->get('/chat-tester')
            ->assertOk()
            ->assertDontSee('Salon A private reply');

        $this->actingAs($userA)
            ->get('/chat-tester')
            ->assertOk()
            ->assertSee('Salon A private reply');
    }

    public function test_workspace_slug_is_uniquified_on_duplicate_names(): void
    {
        $userOne = User::factory()->create(['name' => 'Morgan Owner']);
        $workspaceOne = app(CreateWorkspaceForUser::class)->handle($userOne);
        $userTwo = User::factory()->create(['name' => 'Morgan Owner']);
        $workspaceTwo = app(CreateWorkspaceForUser::class)->handle($userTwo);

        $this->assertSame('morgan-owner-workspace', $workspaceOne->slug);
        $this->assertSame('morgan-owner-workspace-2', $workspaceTwo->slug);
        $this->assertNotSame($workspaceOne->id, $workspaceTwo->id);
    }
}
