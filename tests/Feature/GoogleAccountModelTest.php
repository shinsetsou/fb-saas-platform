<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAccountModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_has_one_encrypted_google_account(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $account = $workspace->googleAccount()->create([
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-456',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
        ]);

        $this->assertSame('access-123', $workspace->fresh()->googleAccount->access_token);
        $this->assertSame('primary', $account->calendar_id);
        $raw = \DB::table('google_accounts')->where('id', $account->id)->value('access_token');
        $this->assertNotSame('access-123', $raw);
    }
}
