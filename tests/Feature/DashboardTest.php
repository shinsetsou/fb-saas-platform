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
}
