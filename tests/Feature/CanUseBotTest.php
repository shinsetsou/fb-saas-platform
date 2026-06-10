<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanUseBotTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        return app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
    }

    public function test_true_during_active_trial(): void
    {
        $workspace = $this->workspace();
        $this->assertTrue($workspace->canUseBot());
    }

    public function test_false_when_trial_expired_and_no_subscription(): void
    {
        $workspace = $this->workspace();
        $workspace->trial->update(['status' => 'expired', 'ends_at' => now()->subDay()]);

        $this->assertFalse($workspace->fresh()->canUseBot());
    }

    public function test_true_when_subscribed_even_if_trial_expired(): void
    {
        $workspace = $this->workspace();
        $workspace->trial->update(['status' => 'expired', 'ends_at' => now()->subDay()]);
        $workspace->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active',
            'stripe_price' => 'price_base', 'quantity' => 1,
        ]);

        $this->assertTrue($workspace->fresh()->canUseBot());
    }
}
