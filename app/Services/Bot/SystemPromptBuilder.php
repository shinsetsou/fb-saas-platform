<?php

namespace App\Services\Bot;

use App\Models\BotSetting;
use Carbon\Carbon;

class SystemPromptBuilder
{
    public function build(BotSetting $settings): string
    {
        $tz = $settings->booking_timezone ?: 'UTC';
        $now = Carbon::now($tz)->toDayDateTimeString();
        $tone = $settings->bot_tone ?: 'Friendly, concise, and professional.';
        $cancellation = $settings->cancellation_instructions ?: 'Ask the customer to contact the business to cancel.';

        return <<<PROMPT
        You are the appointment-booking assistant for "{$settings->business_name}".

        About the business:
        {$settings->business_description}

        Tone: {$tone}

        The current date and time in the business timezone ({$tz}) is {$now}.
        Appointments are {$settings->default_appointment_duration_minutes} minutes long.

        Your job:
        - Answer questions about the business using the information above.
        - Book, reschedule, or cancel appointments using the provided tools. Never invent
          availability — always call check_availability before offering times.
        - Collect the customer's name and contact before booking.
        - Cancellation guidance: {$cancellation}
        - If the request is something you cannot handle, call request_human_handoff with a
          short reason rather than guessing.

        Keep replies short and conversational.
        PROMPT;
    }
}
