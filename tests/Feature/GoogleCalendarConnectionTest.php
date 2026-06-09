<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Mockery;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_stores_google_account(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $socialiteUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $socialiteUser->token = 'access-xyz';
        $socialiteUser->refreshToken = 'refresh-xyz';
        $socialiteUser->expiresIn = 3600;

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $this->mock(Socialite::class)->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)
            ->get('/google/callback?code=test-code')
            ->assertRedirect('/dashboard');

        $this->assertDatabaseHas('google_accounts', ['workspace_id' => $workspace->id, 'calendar_id' => 'primary']);
        $this->assertSame('access-xyz', $workspace->fresh()->googleAccount->access_token);
    }

    public function test_disconnect_removes_account(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->googleAccount()->create([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
        ]);

        $this->actingAs($user)
            ->delete('/google/disconnect')
            ->assertRedirect('/dashboard');

        $this->assertDatabaseMissing('google_accounts', ['workspace_id' => $workspace->id]);
    }

    public function test_connect_redirects_to_google(): void
    {
        $user = User::factory()->create();
        app(CreateWorkspaceForUser::class)->handle($user);

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        $this->mock(Socialite::class)->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)
            ->get('/google/connect')
            ->assertRedirectContains('accounts.google.com');
    }
}
