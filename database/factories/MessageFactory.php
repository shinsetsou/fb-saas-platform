<?php

namespace Database\Factories;

use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'role' => 'user',
            'content' => fake()->sentence(),
            'tool_calls' => null,
        ];
    }
}
