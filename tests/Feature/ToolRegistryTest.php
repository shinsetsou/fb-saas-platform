<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Bot\Tools\ToolContext;
use App\Services\Bot\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function context(): ToolContext
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);

        return new ToolContext($workspace->fresh(), $conversation);
    }

    public function test_definitions_expose_six_tools(): void
    {
        $names = array_column(app(ToolRegistry::class)->definitions(), 'name');

        $this->assertEqualsCanonicalizing([
            'check_availability', 'book_appointment', 'find_booking',
            'reschedule_appointment', 'cancel_appointment', 'request_human_handoff',
        ], $names);
    }

    public function test_book_appointment_creates_record(): void
    {
        $context = $this->context();

        $result = app(ToolRegistry::class)->execute('book_appointment', [
            'starts_at' => '2026-06-15T09:00:00+00:00',
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
        ], $context);

        $this->assertDatabaseHas('appointments', [
            'customer_contact' => 'sam@example.com',
            'status' => 'booked',
        ]);
        $this->assertStringContainsString('booked', strtolower($result));
    }

    public function test_handoff_marks_conversation(): void
    {
        $context = $this->context();

        app(ToolRegistry::class)->execute('request_human_handoff', ['reason' => 'angry customer'], $context);

        $this->assertSame('needs_human', $context->conversation->fresh()->status);
    }
}
