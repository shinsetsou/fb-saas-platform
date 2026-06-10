<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacebookPageModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $page = $workspace->facebookPage()->create([
            'page_id' => '123',
            'page_name' => 'My Page',
            'page_access_token' => 'page-token',
            'user_access_token' => 'user-token',
            'subscribed_at' => now(),
        ]);

        $raw = \DB::table('facebook_pages')->where('id', $page->id)->value('page_access_token');
        $this->assertNotSame('page-token', $raw);
        $this->assertSame('page-token', $page->fresh()->page_access_token);
        $this->assertSame('user-token', $page->fresh()->user_access_token);
    }

    public function test_belongs_to_workspace_and_workspace_has_one(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $page = $workspace->facebookPage()->create([
            'page_id' => '123',
            'page_name' => 'My Page',
            'page_access_token' => 'page-token',
            'subscribed_at' => now(),
        ]);

        $this->assertTrue($workspace->facebookPage()->exists());
        $this->assertSame($workspace->id, $page->workspace->id);
    }
}
