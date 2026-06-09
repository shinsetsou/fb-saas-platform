<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FakeBookingService implements BookingService
{
    public function availability(Workspace $workspace, Carbon $day): array
    {
        $settings = $workspace->botSetting;
        $tz = $settings->booking_timezone ?: 'UTC';
        $duration = (int) $settings->default_appointment_duration_minutes;
        $buffer = (int) $settings->buffer_minutes;
        $hours = $settings->booking_hours ?? [];

        $day = $day->copy()->setTimezone($tz)->startOfDay();
        $weekday = strtolower($day->format('l'));
        $ranges = $hours[$weekday] ?? [];

        $booked = $workspace->appointments()
            ->where('status', 'booked')
            ->whereDate('starts_at', $day->toDateString())
            ->get();

        $slots = [];
        foreach ($ranges as $range) {
            $cursor = $day->copy()->setTimeFromTimeString($range['start']);
            $end = $day->copy()->setTimeFromTimeString($range['end']);

            while ($cursor->copy()->addMinutes($duration)->lte($end)) {
                $slotStart = $cursor->copy();
                $slotEnd = $cursor->copy()->addMinutes($duration);

                $conflict = $booked->first(fn (Appointment $a) => $slotStart->lt($a->ends_at) && $slotEnd->gt($a->starts_at));

                if (! $conflict) {
                    $slots[] = $slotStart->toIso8601String();
                }

                $cursor->addMinutes($duration + $buffer);
            }
        }

        return $slots;
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
