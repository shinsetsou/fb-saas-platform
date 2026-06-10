<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\UsageMeter;
use App\Services\Billing\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeUsageMeter;
use Tests\TestCase;

class UsageRecorderTest extends TestCase
{
    use RefreshDatabase;

    private FakeUsageMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = new FakeUsageMeter;
        $this->app->instance(UsageMeter::class, $this->meter);
    }

    private function assistantMessage(Workspace $workspace, string $channel = 'web'): Message
    {
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => $channel,
            'external_participant_id' => 'p', 'status' => 'active',
        ]);

        return $conversation->messages()->create(['role' => 'assistant', 'content' => 'hi']);
    }

    private function subscribe(Workspace $workspace): void
    {
        $workspace->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.$workspace->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_base',
            'quantity' => 1,
        ]);
        $workspace->update(['stripe_id' => 'cus_'.$workspace->id]);
    }

    public function test_records_local_row_for_each_message(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $message = $this->assistantMessage($workspace);

        app(UsageRecorder::class)->record($message);

        $this->assertDatabaseHas('usage_records', [
            'workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web',
        ]);
    }

    public function test_is_idempotent_on_message_id(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $message = $this->assistantMessage($workspace);

        app(UsageRecorder::class)->record($message);
        app(UsageRecorder::class)->record($message);

        $this->assertDatabaseCount('usage_records', 1);
    }

    public function test_does_not_push_to_meter_during_trial(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $message = $this->assistantMessage($workspace);

        app(UsageRecorder::class)->record($message);

        $this->assertCount(0, $this->meter->reported);
        $this->assertNull($workspace->usageRecords()->first()->reported_at);
    }

    public function test_pushes_to_meter_and_stamps_when_subscribed(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $this->subscribe($workspace);
        $message = $this->assistantMessage($workspace->fresh());

        app(UsageRecorder::class)->record($message);

        $this->assertCount(1, $this->meter->reported);
        $this->assertSame($workspace->id, $this->meter->reported[0]['workspace_id']);
        $this->assertNotNull($workspace->usageRecords()->first()->reported_at);
    }
}
