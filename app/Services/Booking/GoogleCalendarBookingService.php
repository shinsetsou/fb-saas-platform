<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use App\Services\Booking\Google\GoogleCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GoogleCalendarBookingService implements BookingService
{
    public function __construct(
        private GoogleCalendar $calendar,
        private SlotGenerator $slots,
    ) {}

    public function availability(Workspace $workspace, Carbon $day): array
    {
        $candidates = $this->slots->generate($workspace->botSetting, $day);
        if ($candidates === []) {
            return [];
        }

        $account = $workspace->googleAccount;
        $from = $candidates[0]['start'];
        $to = $candidates[count($candidates) - 1]['end'];
        $busy = $this->calendar->freeBusy($account, $from, $to);

        $open = [];
        foreach ($candidates as $slot) {
            $conflict = false;
            foreach ($busy as $interval) {
                if ($slot['start']->lt($interval['end']) && $slot['end']->gt($interval['start'])) {
                    $conflict = true;
                    break;
                }
            }
            if (! $conflict) {
                $open[] = $slot['start']->toIso8601String();
            }
        }

        return $open;
    }

    public function book(Workspace $workspace, Carbon $startsAt, array $customer, ?int $conversationId = null): Appointment
    {
        $account = $workspace->googleAccount;
        $duration = (int) $workspace->botSetting->default_appointment_duration_minutes;
        $endsAt = $startsAt->copy()->addMinutes($duration);

        $eventId = $this->calendar->insertEvent($account, [
            'summary' => "Appointment: {$customer['name']}",
            'start' => $startsAt,
            'end' => $endsAt,
            'attendee_email' => str_contains($customer['contact'], '@') ? $customer['contact'] : null,
        ]);

        return $workspace->appointments()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversationId,
            'customer_name' => $customer['name'],
            'customer_contact' => $customer['contact'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'booked',
            'external_event_id' => $eventId,
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
        $workspace = $appointment->workspace;
        $duration = (int) $workspace->botSetting->default_appointment_duration_minutes;
        $endsAt = $newStartsAt->copy()->addMinutes($duration);

        $this->calendar->patchEvent($workspace->googleAccount, $appointment->external_event_id, [
            'summary' => "Appointment: {$appointment->customer_name}",
            'start' => $newStartsAt,
            'end' => $endsAt,
            'attendee_email' => str_contains($appointment->customer_contact, '@') ? $appointment->customer_contact : null,
        ]);

        $appointment->update(['starts_at' => $newStartsAt, 'ends_at' => $endsAt]);

        return $appointment->fresh();
    }

    public function cancel(Appointment $appointment): void
    {
        $this->calendar->deleteEvent($appointment->workspace->googleAccount, $appointment->external_event_id);
        $appointment->update(['status' => 'cancelled']);
    }
}
