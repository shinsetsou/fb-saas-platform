<?php

namespace Database\Factories;

use App\Models\FacebookPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FacebookPage> */
class FacebookPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'page_name' => $this->faker->company(),
            'page_access_token' => 'test-page-token',
            'user_access_token' => 'test-user-token',
            'subscribed_at' => now(),
        ];
    }
}
