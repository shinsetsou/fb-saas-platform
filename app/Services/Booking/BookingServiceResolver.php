<?php

namespace App\Services\Booking;

use App\Models\Workspace;
use App\Services\Booking\Google\GoogleCalendar;

class BookingServiceResolver
{
    public function __construct(
        private GoogleCalendar $calendar,
        private SlotGenerator $slots,
    ) {}

    public function for(Workspace $workspace): ?BookingService
    {
        if (! $workspace->googleAccount()->exists()) {
            return null;
        }

        return new GoogleCalendarBookingService($this->calendar, $this->slots);
    }
}
