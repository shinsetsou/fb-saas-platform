<?php

namespace Tests\Unit;

use App\Support\BookingHours;
use PHPUnit\Framework\TestCase;

class BookingHoursTest extends TestCase
{
    public function test_normalize_returns_null_for_non_array(): void
    {
        $this->assertNull(BookingHours::normalize(null));
        $this->assertNull(BookingHours::normalize('not-an-array'));
    }

    public function test_normalize_returns_null_when_no_entries(): void
    {
        $this->assertNull(BookingHours::normalize([
            'monday' => [],
            'tuesday' => [['start' => '', 'end' => '']],
        ]));
    }

    public function test_normalize_drops_partial_ranges(): void
    {
        $out = BookingHours::normalize([
            'friday' => [
                ['start' => '09:00', 'end' => '12:00'],
                ['start' => '13:00'],
                ['end' => '17:00'],
            ],
        ]);

        $this->assertSame([
            'monday' => [],
            'tuesday' => [],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [['start' => '09:00', 'end' => '12:00']],
            'saturday' => [],
            'sunday' => [],
        ], $out);
    }

    public function test_validate_accepts_well_formed_hours(): void
    {
        $hours = [
            'monday' => [['start' => '09:00', 'end' => '12:00']],
            'tuesday' => [],
        ];

        $this->assertSame([], BookingHours::validate($hours));
    }

    public function test_validate_rejects_end_before_start(): void
    {
        $hours = [
            'monday' => [['start' => '12:00', 'end' => '09:00']],
        ];

        $errors = BookingHours::validate($hours);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('end time must be later', $errors[0]);
    }

    public function test_validate_rejects_malformed_times(): void
    {
        $hours = [
            'monday' => [
                ['start' => '9am', 'end' => '12:00'],
            ],
            'tuesday' => [
                ['start' => '09:00', 'end' => '5pm'],
            ],
        ];

        $errors = BookingHours::validate($hours);
        $this->assertCount(2, $errors);
    }

    public function test_validate_rejects_overlapping_ranges(): void
    {
        $hours = [
            'monday' => [
                ['start' => '09:00', 'end' => '13:00'],
                ['start' => '12:00', 'end' => '17:00'],
            ],
        ];

        $errors = BookingHours::validate($hours);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must not overlap', $errors[0]);
    }

    public function test_validate_accepts_back_to_back_ranges(): void
    {
        $hours = [
            'monday' => [
                ['start' => '09:00', 'end' => '12:00'],
                ['start' => '12:00', 'end' => '17:00'],
            ],
        ];

        $this->assertSame([], BookingHours::validate($hours));
    }
}
