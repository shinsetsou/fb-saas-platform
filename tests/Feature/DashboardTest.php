<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_workspace_trial_and_bot_status(): void
    {
        $user = User::factory()->create(['name' => 'Morgan Owner']);
        app(CreateWorkspaceForUser::class)->handle($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Morgan Owner Workspace')
            ->assertSee('Trialing')
            ->assertSee('Bot disabled')
            ->assertSee('Facebook not connected')
            ->assertSee('Google Calendar not connected');
    }

    public function test_dashboard_shows_facebook_connected_when_page_exists(): void
    {
        $user = User::factory()->create(['name' => 'Morgan Owner']);
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->facebookPage()->create([
            'page_id' => '555', 'page_name' => 'My Shop', 'page_access_token' => 'tok', 'subscribed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Facebook connected')
            ->assertSee('My Shop');
    }

    public function test_dashboard_shows_google_connected_when_account_exists(): void
    {
        $user = User::factory()->create(['name' => 'Morgan Owner']);
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->googleAccount()->create([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Google Calendar connected');
    }

    public function test_dashboard_shows_trialing_billing_status(): void
    {
        $user = User::factory()->create(['name' => 'Morgan Owner']);
        app(CreateWorkspaceForUser::class)->handle($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Trialing')
            ->assertSee('Subscribe');
    }

    public function test_dashboard_shows_subscribed_status_and_manage_button(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active',
            'stripe_price' => 'price_base', 'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Subscribed')
            ->assertSee('Manage billing');
    }

    public function test_dashboard_shows_lapsed_when_trial_expired_and_unsubscribed(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->trial->update(['status' => 'expired', 'ends_at' => now()->subDay()]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Lapsed')
            ->assertSee('Subscribe');
    }
}
