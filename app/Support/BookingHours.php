<?php

namespace App\Support;

class BookingHours
{
    public const WEEKDAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    public const TIME_REGEX = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * Normalize a raw request payload into the canonical shape:
     *   ['monday' => [['start' => '09:00', 'end' => '12:00'], ...], ...]
     *
     * Empty weekdays collapse to an empty array. If the entire payload has
     * no entries at all, returns null (treated as "closed every day" by the
     * slot generator).
     *
     * @param  array<string, mixed>|null  $raw
     * @return array<string, array<int, array{start: string, end: string}>>|null
     */
    public static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $out = [];
        $anyEntry = false;

        foreach (self::WEEKDAYS as $day) {
            $ranges = $raw[$day] ?? null;
            $out[$day] = [];

            if (! is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $range) {
                if (! is_array($range)) {
                    continue;
                }

                $start = isset($range['start']) && is_string($range['start']) ? $range['start'] : null;
                $end = isset($range['end']) && is_string($range['end']) ? $range['end'] : null;

                if ($start === null || $end === null || $start === '' || $end === '') {
                    continue;
                }

                $out[$day][] = ['start' => $start, 'end' => $end];
                $anyEntry = true;
            }
        }

        return $anyEntry ? $out : null;
    }

    /**
     * Validate a normalized booking_hours array.
     *
     * @param  array<string, array<int, array{start: string, end: string}>>|null  $hours
     * @return array<int, string> List of human-readable error messages (empty if valid).
     */
    public static function validate(?array $hours): array
    {
        if ($hours === null) {
            return [];
        }

        $errors = [];

        foreach ($hours as $day => $ranges) {
            foreach ($ranges as $i => $range) {
                $label = ucfirst($day).' range '.($i + 1);

                if (! preg_match(self::TIME_REGEX, $range['start'])) {
                    $errors[] = "{$label}: start time must be in HH:MM format.";

                    continue;
                }
                if (! preg_match(self::TIME_REGEX, $range['end'])) {
                    $errors[] = "{$label}: end time must be in HH:MM format.";

                    continue;
                }
                if (strcmp($range['start'], $range['end']) >= 0) {
                    $errors[] = "{$label}: end time must be later than start time.";
                }
            }

            $sorted = $ranges;
            usort($sorted, fn ($a, $b) => strcmp($a['start'], $b['start']));
            for ($i = 1; $i < count($sorted); $i++) {
                if (strcmp($sorted[$i - 1]['end'], $sorted[$i]['start']) > 0) {
                    $errors[] = ucfirst($day).': ranges must not overlap.';
                    break;
                }
            }
        }

        return $errors;
    }
}
