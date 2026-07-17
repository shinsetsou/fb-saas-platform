<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Jobs\ProcessIncomingMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FacebookWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.verify_token', 'verify-secret');
        config()->set('services.facebook.client_secret', 'app-secret');
    }

    private function connectedWorkspace(bool $botEnabled = true, bool $trialActive = true): Workspace
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->facebookPage()->create([
            'page_id' => 'PAGE_1',
            'page_name' => 'Shop',
            'page_access_token' => 'page-tok',
            'subscribed_at' => now(),
        ]);
        $workspace->botSetting->update(['is_enabled' => $botEnabled]);
        $workspace->trial->update([
            'status' => $trialActive ? 'trialing' : 'expired',
            'ends_at' => $trialActive ? now()->addDays(5) : now()->subDay(),
        ]);

        return $workspace->fresh();
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret');

        return $this->call('POST', '/webhooks/facebook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function messagePayload(string $pageId, string $senderId, string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $senderId],
                    'recipient' => ['id' => $pageId],
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    public function test_verify_returns_challenge_for_correct_token(): void
    {
        $this->get('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=verify-secret&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_verify_returns_403_for_wrong_token(): void
    {
        $this->get('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
            ->assertForbidden();
    }

    public function test_verify_returns_403_when_configured_token_is_empty(): void
    {
        config()->set('services.facebook.verify_token', '');

        $this->get('/webhooks/facebook?hub_mode=subscribe&hub_verify_token=&hub_challenge=12345')
            ->assertForbidden();
    }

    public function test_receive_returns_403_for_invalid_signature(): void
    {
        Queue::fake();
        $body = json_encode($this->messagePayload('PAGE_1', 'psid', 'mid-1', 'hi'));

        $this->call('POST', '/webhooks/facebook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_receive_returns_403_for_missing_signature(): void
    {
        Queue::fake();
        $body = json_encode($this->messagePayload('PAGE_1', 'psid', 'mid-1', 'hi'));

        $this->call('POST', '/webhooks/facebook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_receive_dispatches_job_for_known_page(): void
    {
        Queue::fake();
        $workspace = $this->connectedWorkspace();

        $this->postWebhook($this->messagePayload('PAGE_1', 'psid-9', 'mid-1', 'hello'))
            ->assertOk();

        Queue::assertPushed(ProcessIncomingMessage::class, function ($job) use ($workspace) {
            return $job->workspaceId === $workspace->id
                && $job->channel === 'facebook'
                && $job->participantId === 'psid-9'
                && $job->text === 'hello';
        });
        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'facebook',
            'event_id' => 'mid-1',
            'processed_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_duplicate_delivery_processes_once(): void
    {
        Queue::fake();
        $this->connectedWorkspace();

        $payload = $this->messagePayload('PAGE_1', 'psid-9', 'mid-dup', 'hello');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        Queue::assertPushed(ProcessIncomingMessage::class, 1);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_unknown_page_is_acked_without_dispatch(): void
    {
        Queue::fake();

        $this->postWebhook($this->messagePayload('UNKNOWN', 'psid', 'mid-x', 'hi'))
            ->assertOk();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_disabled_bot_does_not_dispatch(): void
    {
        Queue::fake();
        $this->connectedWorkspace(botEnabled: false);

        $this->postWebhook($this->messagePayload('PAGE_1', 'psid', 'mid-2', 'hi'))
            ->assertOk();

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'facebook',
            'event_id' => 'mid-2',
            'processed_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_expired_trial_does_not_dispatch(): void
    {
        Queue::fake();
        $this->connectedWorkspace(trialActive: false);

        $this->postWebhook($this->messagePayload('PAGE_1', 'psid', 'mid-3', 'hi'))
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_subscribed_workspace_with_expired_trial_dispatches(): void
    {
        Queue::fake();
        $workspace = $this->connectedWorkspace(trialActive: false);
        $workspace->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_x', 'stripe_status' => 'active',
            'stripe_price' => 'price_base', 'quantity' => 1,
        ]);

        $this->postWebhook($this->messagePayload('PAGE_1', 'psid', 'mid-sub', 'hi'))
            ->assertOk();

        Queue::assertPushed(ProcessIncomingMessage::class);
    }
}
