<?php

namespace Tests\Support;

use App\Models\GoogleAccount;
use App\Services\Booking\Google\GoogleCalendar;
use Carbon\Carbon;

class FakeGoogleCalendar implements GoogleCalendar
{
    /** @var array<int, array{start: Carbon, end: Carbon}> */
    public array $busy;

    /** @var array<int, array<string, mixed>> */
    public array $inserted = [];

    /** @var array<int, array<string, mixed>> */
    public array $patched = [];

    /** @var array<int, string> */
    public array $deleted = [];

    private int $counter = 0;

    /** @param array<int, array{start: Carbon, end: Carbon}> $busy */
    public function __construct(array $busy = [])
    {
        $this->busy = $busy;
    }

    public function freeBusy(GoogleAccount $account, Carbon $from, Carbon $to): array
    {
        return $this->busy;
    }

    public function insertEvent(GoogleAccount $account, array $event): string
    {
        $this->inserted[] = $event;

        return 'fake-event-'.(++$this->counter);
    }

    public function patchEvent(GoogleAccount $account, string $eventId, array $event): void
    {
        $this->patched[] = ['id' => $eventId] + $event;
    }

    public function deleteEvent(GoogleAccount $account, string $eventId): void
    {
        $this->deleted[] = $eventId;
    }
}
