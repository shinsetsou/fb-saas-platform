<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Booking\GoogleCalendarBookingService;
use App\Services\Booking\SlotGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class GoogleCalendarBookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function connectedWorkspace(): \App\Models\Workspace
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'default_appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);
        $workspace->googleAccount()->create([
            'access_token' => 'a', 'refresh_token' => 'r',
            'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
        ]);

        return $workspace->fresh();
    }

    public function test_availability_excludes_busy_intervals(): void
    {
        $workspace = $this->connectedWorkspace();
        $calendar = new FakeGoogleCalendar(busy: [
            ['start' => Carbon::parse('2026-06-15T09:00:00+00:00'), 'end' => Carbon::parse('2026-06-15T09:30:00+00:00')],
        ]);
        $service = new GoogleCalendarBookingService($calendar, app(SlotGenerator::class));

        $slots = $service->availability($workspace, Carbon::parse('2026-06-15', 'UTC'));

        $this->assertNotContains('2026-06-15T09:00:00+00:00', $slots);
        $this->assertCount(3, $slots);
    }

    public function test_book_inserts_event_and_stores_external_id(): void
    {
        $workspace = $this->connectedWorkspace();
        $calendar = new FakeGoogleCalendar();
        $service = new GoogleCalendarBookingService($calendar, app(SlotGenerator::class));

        $appointment = $service->book(
            $workspace,
            Carbon::parse('2026-06-15T09:00:00+00:00'),
            ['name' => 'Sam', 'contact' => 'sam@example.com'],
        );

        $this->assertCount(1, $calendar->inserted);
        $this->assertSame('fake-event-1', $appointment->external_event_id);
        $this->assertDatabaseHas('appointments', ['external_event_id' => 'fake-event-1', 'status' => 'booked']);
    }

    public function test_cancel_deletes_event_and_marks_cancelled(): void
    {
        $workspace = $this->connectedWorkspace();
        $calendar = new FakeGoogleCalendar();
        $service = new GoogleCalendarBookingService($calendar, app(SlotGenerator::class));
        $appointment = $service->book($workspace, Carbon::parse('2026-06-15T09:00:00+00:00'), ['name' => 'Sam', 'contact' => 'sam@example.com']);

        $service->cancel($appointment);

        $this->assertContains('fake-event-1', $calendar->deleted);
        $this->assertSame('cancelled', $appointment->fresh()->status);
    }
}
