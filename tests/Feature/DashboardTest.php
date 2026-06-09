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
}
