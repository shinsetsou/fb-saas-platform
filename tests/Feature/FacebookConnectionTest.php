<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Facebook\FacebookGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Contracts\Provider;
use Mockery;
use Tests\Support\FakeFacebookGraph;
use Tests\TestCase;

class FacebookConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_redirects_to_facebook(): void
    {
        $user = User::factory()->create();
        app(CreateWorkspaceForUser::class)->handle($user);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://www.facebook.com/v21.0/dialog/oauth'));
        $this->mock(Socialite::class)->shouldReceive('driver')->with('facebook')->andReturn($provider);

        $this->actingAs($user)
            ->get('/facebook/connect')
            ->assertRedirectContains('facebook.com');
    }

    public function test_callback_stores_page_and_subscribes(): void
    {
        $fake = new FakeFacebookGraph([
            ['id' => '555', 'name' => 'My Shop', 'access_token' => 'page-tok-555'],
        ]);
        $this->app->instance(FacebookGraph::class, $fake);

        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $socialiteUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $socialiteUser->token = 'short-user-token';

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $this->mock(Socialite::class)->shouldReceive('driver')->with('facebook')->andReturn($provider);

        $this->actingAs($user)
            ->get('/facebook/callback?code=test-code')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'facebook-connected');

        $this->assertDatabaseHas('facebook_pages', [
            'workspace_id' => $workspace->id,
            'page_id' => '555',
            'page_name' => 'My Shop',
        ]);
        $page = $workspace->fresh()->facebookPage;
        $this->assertSame('page-tok-555', $page->page_access_token);
        $this->assertSame('long-lived-short-user-token', $page->user_access_token);
        $this->assertNotNull($page->subscribed_at);

        $this->assertCount(1, $fake->subscribed);
        $this->assertSame(['page_id' => '555', 'page_token' => 'page-tok-555'], $fake->subscribed[0]);
    }

    public function test_callback_with_error_redirects_without_storing(): void
    {
        $user = User::factory()->create();
        app(CreateWorkspaceForUser::class)->handle($user);

        $this->actingAs($user)
            ->get('/facebook/callback?error=access_denied')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'facebook-connect-failed');

        $this->assertDatabaseCount('facebook_pages', 0);
    }

    public function test_callback_with_no_pages_redirects_with_failure(): void
    {
        $this->app->instance(FacebookGraph::class, new FakeFacebookGraph([]));

        $user = User::factory()->create();
        app(CreateWorkspaceForUser::class)->handle($user);

        $socialiteUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $socialiteUser->token = 'short-user-token';
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $this->mock(Socialite::class)->shouldReceive('driver')->with('facebook')->andReturn($provider);

        $this->actingAs($user)
            ->get('/facebook/callback?code=test-code')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'facebook-connect-failed');

        $this->assertDatabaseCount('facebook_pages', 0);
    }

    public function test_callback_redirects_with_failure_when_graph_throws(): void
    {
        $graph = Mockery::mock(FacebookGraph::class);
        $graph->shouldReceive('exchangeForLongLivedToken')->andReturn('long-token');
        $graph->shouldReceive('pages')->andReturn([
            ['id' => '555', 'name' => 'My Shop', 'access_token' => 'page-tok-555'],
        ]);
        $graph->shouldReceive('subscribeApp')->andThrow(new \RuntimeException('graph down'));
        $this->app->instance(FacebookGraph::class, $graph);

        $user = User::factory()->create();
        app(CreateWorkspaceForUser::class)->handle($user);

        $socialiteUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $socialiteUser->token = 'short-user-token';
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $this->mock(Socialite::class)->shouldReceive('driver')->with('facebook')->andReturn($provider);

        $this->actingAs($user)
            ->get('/facebook/callback?code=test-code')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'facebook-connect-failed');

        $this->assertDatabaseCount('facebook_pages', 0);
    }

    public function test_disconnect_removes_page(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->facebookPage()->create([
            'page_id' => '555',
            'page_name' => 'My Shop',
            'page_access_token' => 'tok',
            'subscribed_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete('/facebook/disconnect')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'facebook-disconnected');

        $this->assertDatabaseMissing('facebook_pages', ['workspace_id' => $workspace->id]);
    }
}
