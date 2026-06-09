<?php

namespace App\Services\Booking\Google;

use App\Models\GoogleAccount;
use Carbon\Carbon;

interface GoogleCalendar
{
    /**
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    public function freeBusy(GoogleAccount $account, Carbon $from, Carbon $to): array;

    /**
     * @param  array{summary: string, start: Carbon, end: Carbon, attendee_email: ?string}  $event
     */
    public function insertEvent(GoogleAccount $account, array $event): string;

    /**
     * @param  array{summary: string, start: Carbon, end: Carbon, attendee_email: ?string}  $event
     */
    public function patchEvent(GoogleAccount $account, string $eventId, array $event): void;

    public function deleteEvent(GoogleAccount $account, string $eventId): void;
}
