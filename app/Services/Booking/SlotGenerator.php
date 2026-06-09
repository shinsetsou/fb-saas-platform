<?php

namespace App\Services\Booking;

use App\Models\BotSetting;
use Carbon\Carbon;

class SlotGenerator
{
    /**
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    public function generate(BotSetting $settings, Carbon $day): array
    {
        $tz = $settings->booking_timezone ?: 'UTC';
        $duration = (int) $settings->default_appointment_duration_minutes;
        $buffer = (int) $settings->buffer_minutes;
        $hours = $settings->booking_hours ?? [];

        $day = $day->copy()->setTimezone($tz)->startOfDay();
        $weekday = strtolower($day->format('l'));
        $ranges = $hours[$weekday] ?? [];

        $slots = [];
        foreach ($ranges as $range) {
            $cursor = $day->copy()->setTimeFromTimeString($range['start']);
            $end = $day->copy()->setTimeFromTimeString($range['end']);

            while ($cursor->copy()->addMinutes($duration)->lte($end)) {
                $slots[] = [
                    'start' => $cursor->copy(),
                    'end' => $cursor->copy()->addMinutes($duration),
                ];
                $cursor->addMinutes($duration + $buffer);
            }
        }

        return $slots;
    }
}
