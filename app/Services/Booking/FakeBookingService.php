<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FakeBookingService implements BookingService
{
    public function __construct(private SlotGenerator $slots) {}

    public function availability(Workspace $workspace, Carbon $day): array
    {
        $candidates = $this->slots->generate($workspace->botSetting, $day);
        if ($candidates === []) {
            return [];
        }

        $booked = $workspace->appointments()
            ->where('status', 'booked')
            ->whereDate('starts_at', $candidates[0]['start']->toDateString())
            ->get();

        $open = [];
        foreach ($candidates as $slot) {
            $conflict = $booked->first(fn (Appointment $a) => $slot['start']->lt($a->ends_at) && $slot['end']->gt($a->starts_at));
            if (! $conflict) {
                $open[] = $slot['start']->toIso8601String();
            }
        }

        return $open;
    }

    public function book(Workspace $workspace, Carbon $startsAt, array $customer, ?int $conversationId = null): Appointment
    {
        $duration = (int) $workspace->botSetting->default_appointment_duration_minutes;

        return $workspace->appointments()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversationId,
            'customer_name' => $customer['name'],
            'customer_contact' => $customer['contact'],
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($duration),
            'status' => 'booked',
        ]);
    }

    public function find(Workspace $workspace, string $customerContact): Collection
    {
        return $workspace->appointments()
            ->where('customer_contact', $customerContact)
            ->where('status', 'booked')
            ->orderBy('starts_at')
            ->get();
    }

    public function reschedule(Appointment $appointment, Carbon $newStartsAt): Appointment
    {
        $duration = (int) $appointment->workspace->botSetting->default_appointment_duration_minutes;
        $appointment->update([
            'starts_at' => $newStartsAt,
            'ends_at' => $newStartsAt->copy()->addMinutes($duration),
        ]);

        return $appointment->fresh();
    }

    public function cancel(Appointment $appointment): void
    {
        $appointment->update(['status' => 'cancelled']);
    }
}
