<?php

namespace App\Services\Booking\Google;

use App\Models\GoogleAccount;
use Carbon\Carbon;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;

class GoogleApiCalendar implements GoogleCalendar
{
    public function __construct(private GoogleClientFactory $clients) {}

    public function freeBusy(GoogleAccount $account, Carbon $from, Carbon $to): array
    {
        $service = new Calendar($this->clients->for($account));

        $request = new FreeBusyRequest;
        $request->setTimeMin($from->toRfc3339String());
        $request->setTimeMax($to->toRfc3339String());
        $item = new FreeBusyRequestItem;
        $item->setId($account->calendar_id);
        $request->setItems([$item]);

        $response = $service->freebusy->query($request);
        $calendars = $response->getCalendars();
        $busyPeriods = $calendars[$account->calendar_id]->getBusy() ?? [];

        $busy = [];
        foreach ($busyPeriods as $period) {
            $busy[] = [
                'start' => Carbon::parse($period->getStart()),
                'end' => Carbon::parse($period->getEnd()),
            ];
        }

        return $busy;
    }

    public function insertEvent(GoogleAccount $account, array $event): string
    {
        $service = new Calendar($this->clients->for($account));
        $created = $service->events->insert($account->calendar_id, $this->toEvent($event));

        return $created->getId();
    }

    public function patchEvent(GoogleAccount $account, string $eventId, array $event): void
    {
        $service = new Calendar($this->clients->for($account));
        $service->events->patch($account->calendar_id, $eventId, $this->toEvent($event));
    }

    public function deleteEvent(GoogleAccount $account, string $eventId): void
    {
        $service = new Calendar($this->clients->for($account));
        $service->events->delete($account->calendar_id, $eventId);
    }

    /** @param array{summary: string, start: Carbon, end: Carbon, attendee_email: ?string} $event */
    private function toEvent(array $event): Event
    {
        $start = new EventDateTime;
        $start->setDateTime($event['start']->toRfc3339String());
        $end = new EventDateTime;
        $end->setDateTime($event['end']->toRfc3339String());

        $google = new Event;
        $google->setSummary($event['summary']);
        $google->setStart($start);
        $google->setEnd($end);

        if (! empty($event['attendee_email'])) {
            $attendee = new EventAttendee;
            $attendee->setEmail($event['attendee_email']);
            $google->setAttendees([$attendee]);
        }

        return $google;
    }
}
