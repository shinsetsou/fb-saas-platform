<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\Google\GoogleCalendar;
use App\Services\Bot\Tools\ToolContext;
use App\Services\Bot\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GoogleCalendar::class, new FakeGoogleCalendar());
    }

    private function context(bool $connected): ToolContext
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        if ($connected) {
            $workspace->googleAccount()->create([
                'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
            ]);
        }
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => 'web',
            'external_participant_id' => 'tester', 'status' => 'active',
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

    public function test_book_appointment_creates_record_when_connected(): void
    {
        $context = $this->context(connected: true);

        $result = app(ToolRegistry::class)->execute('book_appointment', [
            'starts_at' => '2026-06-15T09:00:00+00:00',
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
        ], $context);

        $this->assertDatabaseHas('appointments', ['customer_contact' => 'sam@example.com', 'status' => 'booked']);
        $this->assertStringContainsString('booked', strtolower($result));
    }

    public function test_booking_tool_hands_off_when_not_connected(): void
    {
        $context = $this->context(connected: false);

        $result = app(ToolRegistry::class)->execute('book_appointment', [
            'starts_at' => '2026-06-15T09:00:00+00:00',
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
        ], $context);

        $this->assertStringContainsString("isn't connected", $result);
        $this->assertSame('needs_human', $context->conversation->fresh()->status);
        $this->assertDatabaseMissing('appointments', ['customer_contact' => 'sam@example.com']);
    }

    public function test_handoff_marks_conversation(): void
    {
        $context = $this->context(connected: false);

        app(ToolRegistry::class)->execute('request_human_handoff', ['reason' => 'angry customer'], $context);

        $this->assertSame('needs_human', $context->conversation->fresh()->status);
    }
}
