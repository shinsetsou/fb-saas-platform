<?php

namespace Database\Factories;

use App\Models\GoogleAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoogleAccount> */
class GoogleAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
        ];
    }
}
