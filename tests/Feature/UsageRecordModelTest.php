<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageRecordModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_id_is_unique(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => 'web',
            'external_participant_id' => 'p', 'status' => 'active',
        ]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'hi']);

        UsageRecord::create(['workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web']);

        $this->expectException(QueryException::class);
        UsageRecord::create(['workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web']);
    }

    public function test_workspace_has_many_usage_records(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => 'web',
            'external_participant_id' => 'p', 'status' => 'active',
        ]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'hi']);
        UsageRecord::create(['workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web']);

        $this->assertSame(1, $workspace->usageRecords()->count());
    }
}
