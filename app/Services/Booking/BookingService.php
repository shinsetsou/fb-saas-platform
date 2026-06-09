<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

interface BookingService
{
    /**
     * Open slot start times as ISO-8601 strings, for the given day.
     *
     * @return array<int, string>
     */
    public function availability(Workspace $workspace, Carbon $day): array;

    /** @param array{name: string, contact: string} $customer */
    public function book(Workspace $workspace, Carbon $startsAt, array $customer, ?int $conversationId = null): Appointment;

    /** @return Collection<int, Appointment> */
    public function find(Workspace $workspace, string $customerContact): Collection;

    public function reschedule(Appointment $appointment, Carbon $newStartsAt): Appointment;

    public function cancel(Appointment $appointment): void;
}
