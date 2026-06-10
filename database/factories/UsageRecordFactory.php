<?php

namespace Database\Factories;

use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageRecord> */
class UsageRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel' => 'web',
            'reported_at' => null,
        ];
    }
}
