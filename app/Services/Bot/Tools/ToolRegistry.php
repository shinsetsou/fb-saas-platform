<?php

namespace App\Services\Bot\Tools;

use App\Services\Booking\BookingService;
use App\Services\Booking\BookingServiceResolver;
use Carbon\Carbon;

class ToolRegistry
{
    private const NOT_CONNECTED = "The calendar isn't connected yet — a teammate will follow up.";

    public function __construct(private BookingServiceResolver $resolver) {}

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            [
                'name' => 'check_availability',
                'description' => 'List open appointment slots for a given date. Call this whenever the customer asks about times or before offering any slot.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Date to check, YYYY-MM-DD.'],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'book_appointment',
                'description' => 'Book an appointment at a specific start time. Collect the customer name and contact first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'starts_at' => ['type' => 'string', 'description' => 'ISO-8601 start time, e.g. 2026-06-15T09:00:00+00:00.'],
                        'customer_name' => ['type' => 'string'],
                        'customer_contact' => ['type' => 'string', 'description' => 'Email or phone.'],
                    ],
                    'required' => ['starts_at', 'customer_name', 'customer_contact'],
                ],
            ],
            [
                'name' => 'find_booking',
                'description' => "Find a customer's existing booked appointments by their contact (email or phone).",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_contact' => ['type' => 'string'],
                    ],
                    'required' => ['customer_contact'],
                ],
            ],
            [
                'name' => 'reschedule_appointment',
                'description' => 'Move an existing appointment to a new start time. Use find_booking first to get the appointment_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'appointment_id' => ['type' => 'integer'],
                        'new_starts_at' => ['type' => 'string', 'description' => 'ISO-8601 start time.'],
                    ],
                    'required' => ['appointment_id', 'new_starts_at'],
                ],
            ],
            [
                'name' => 'cancel_appointment',
                'description' => 'Cancel an existing appointment. Use find_booking first to get the appointment_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'appointment_id' => ['type' => 'integer'],
                    ],
                    'required' => ['appointment_id'],
                ],
            ],
            [
                'name' => 'request_human_handoff',
                'description' => 'Flag this conversation for a human when you cannot help. Provide a short reason.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['reason'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(string $name, array $input, ToolContext $context): string
    {
        return match ($name) {
            'check_availability' => $this->checkAvailability($input, $context),
            'book_appointment' => $this->bookAppointment($input, $context),
            'find_booking' => $this->findBooking($input, $context),
            'reschedule_appointment' => $this->rescheduleAppointment($input, $context),
            'cancel_appointment' => $this->cancelAppointment($input, $context),
            'request_human_handoff' => $this->requestHumanHandoff($input, $context),
            default => "Unknown tool: {$name}",
        };
    }

    private function resolveOrHandoff(ToolContext $context): ?BookingService
    {
        $service = $this->resolver->for($context->workspace);
        if ($service === null) {
            $context->conversation->update(['status' => 'needs_human']);
        }

        return $service;
    }

    private function checkAvailability(array $input, ToolContext $context): string
    {
        if (! $booking = $this->resolveOrHandoff($context)) {
            return self::NOT_CONNECTED;
        }
        $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
        $day = Carbon::parse($input['date'], $tz);
        $slots = $booking->availability($context->workspace, $day);

        return $slots === [] ? 'No open slots on that date.' : 'Open slots: '.implode(', ', $slots);
    }

    private function bookAppointment(array $input, ToolContext $context): string
    {
        if (! $booking = $this->resolveOrHandoff($context)) {
            return self::NOT_CONNECTED;
        }
        $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
        $appointment = $booking->book(
            $context->workspace,
            Carbon::parse($input['starts_at'], $tz),
            ['name' => $input['customer_name'], 'contact' => $input['customer_contact']],
            $context->conversation->id,
        );

        return "Appointment booked (id {$appointment->id}) for {$appointment->starts_at->toIso8601String()}.";
    }

    private function findBooking(array $input, ToolContext $context): string
    {
        if (! $booking = $this->resolveOrHandoff($context)) {
            return self::NOT_CONNECTED;
        }
        $found = $booking->find($context->workspace, $input['customer_contact']);
        if ($found->isEmpty()) {
            return 'No booked appointments found for that contact.';
        }

        return $found->map(fn ($a) => "id {$a->id}: {$a->starts_at->toIso8601String()}")->implode('; ');
    }

    private function rescheduleAppointment(array $input, ToolContext $context): string
    {
        if (! $booking = $this->resolveOrHandoff($context)) {
            return self::NOT_CONNECTED;
        }
        $appointment = $context->workspace->appointments()->find($input['appointment_id']);
        if (! $appointment) {
            return 'Appointment not found.';
        }
        $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
        $updated = $booking->reschedule($appointment, Carbon::parse($input['new_starts_at'], $tz));

        return "Rescheduled to {$updated->starts_at->toIso8601String()}.";
    }

    private function cancelAppointment(array $input, ToolContext $context): string
    {
        if (! $booking = $this->resolveOrHandoff($context)) {
            return self::NOT_CONNECTED;
        }
        $appointment = $context->workspace->appointments()->find($input['appointment_id']);
        if (! $appointment) {
            return 'Appointment not found.';
        }
        $booking->cancel($appointment);

        return 'Appointment cancelled.';
    }

    private function requestHumanHandoff(array $input, ToolContext $context): string
    {
        $context->conversation->update(['status' => 'needs_human']);

        return 'A human has been notified and will follow up.';
    }
}
