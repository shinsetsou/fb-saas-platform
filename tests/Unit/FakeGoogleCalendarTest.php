<?php

namespace Tests\Unit;

use App\Models\GoogleAccount;
use Carbon\Carbon;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class FakeGoogleCalendarTest extends TestCase
{
    public function test_records_calls_and_returns_scripted_busy(): void
    {
        $account = new GoogleAccount(['calendar_id' => 'primary']);
        $calendar = new FakeGoogleCalendar(busy: [
            ['start' => Carbon::parse('2026-06-15T09:00:00+00:00'), 'end' => Carbon::parse('2026-06-15T09:30:00+00:00')],
        ]);

        $busy = $calendar->freeBusy($account, Carbon::parse('2026-06-15T00:00:00+00:00'), Carbon::parse('2026-06-15T23:59:59+00:00'));
        $eventId = $calendar->insertEvent($account, [
            'summary' => 'Appointment: Sam',
            'start' => Carbon::parse('2026-06-15T10:00:00+00:00'),
            'end' => Carbon::parse('2026-06-15T10:30:00+00:00'),
            'attendee_email' => 'sam@example.com',
        ]);

        $this->assertCount(1, $busy);
        $this->assertSame('fake-event-1', $eventId);
        $this->assertCount(1, $calendar->inserted);
    }
}
