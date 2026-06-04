<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSetting extends Model
{
    protected $fillable = [
        'workspace_id',
        'is_enabled',
        'business_name',
        'business_description',
        'default_appointment_duration_minutes',
        'booking_timezone',
        'booking_hours',
        'buffer_minutes',
        'cancellation_instructions',
        'bot_tone',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'booking_hours' => 'array',
    ];
}
