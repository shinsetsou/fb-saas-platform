<?php

namespace Tests\Unit;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Booking\FakeBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeBookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceWithHours(): \App\Models\Workspace
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'default_appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);

        return $workspace->fresh();
    }

    public function test_availability_returns_slots_within_hours(): void
    {
        $workspace = $this->workspaceWithHours();
        // 2026-06-15 is a Monday.
        $range = Carbon::parse('2026-06-15', 'UTC');

        $slots = app(FakeBookingService::class)->availability($workspace, $range);

        // 09:00, 09:30, 10:00, 10:30 -> 4 slots (each 30m within 09:00-11:00).
        $this->assertCount(4, $slots);
        $this->assertSame('2026-06-15T09:00:00+00:00', $slots[0]);
    }

    public function test_booked_slot_is_excluded(): void
    {
        $workspace = $this->workspaceWithHours();
        $workspace->appointments()->create([
            'workspace_id' => $workspace->id,
            'customer_name' => 'Taken',
            'customer_contact' => 'taken@example.com',
            'starts_at' => Carbon::parse('2026-06-15T09:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-15T09:30:00', 'UTC'),
            'status' => 'booked',
        ]);
        $range = Carbon::parse('2026-06-15', 'UTC');

        $slots = app(FakeBookingService::class)->availability($workspace, $range);

        $this->assertNotContains('2026-06-15T09:00:00+00:00', $slots);
        $this->assertCount(3, $slots);
    }
}
