<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_workspace_trial_and_bot_settings(): void
    {
        $response = $this->post('/register', [
            'name' => 'Casey Owner',
            'email' => 'casey@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/dashboard');

        $user = User::where('email', 'casey@example.com')->firstOrFail();
        $workspace = $user->workspaces()->first();

        $this->assertNotNull($workspace);
        $this->assertSame('Casey Owner Workspace', $workspace->name);
        $this->assertFalse($workspace->botSetting->is_enabled);
        $this->assertSame('trialing', $workspace->trial->status);
        $this->assertTrue($workspace->trial->ends_at->greaterThan(now()->addDays(13)));
    }
}
