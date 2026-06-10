<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Billable;
use Tests\TestCase;

class WorkspaceBillableTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_is_billable(): void
    {
        $this->assertContains(Billable::class, class_uses_recursive(Workspace::class));
    }

    public function test_stripe_email_is_owner_email(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $this->assertSame('owner@example.com', $workspace->stripeEmail());
        $this->assertSame($workspace->name, $workspace->stripeName());
    }

    public function test_workspaces_table_has_stripe_id_column(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->update(['stripe_id' => 'cus_test123']);

        $this->assertSame('cus_test123', $workspace->fresh()->stripe_id);
    }
}
