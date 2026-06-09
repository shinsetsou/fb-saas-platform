<?php

namespace Tests\Unit;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Booking\SlotGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_candidate_slots_from_hours(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'default_appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);

        $slots = app(SlotGenerator::class)->generate($workspace->fresh()->botSetting, Carbon::parse('2026-06-15', 'UTC'));

        $this->assertCount(4, $slots);
        $this->assertEquals('2026-06-15T09:00:00+00:00', $slots[0]['start']->toIso8601String());
        $this->assertEquals('2026-06-15T09:30:00+00:00', $slots[0]['end']->toIso8601String());
    }

    public function test_closed_day_returns_no_slots(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);

        $slots = app(SlotGenerator::class)->generate($workspace->fresh()->botSetting, Carbon::parse('2026-06-16', 'UTC'));

        $this->assertSame([], $slots);
    }
}
