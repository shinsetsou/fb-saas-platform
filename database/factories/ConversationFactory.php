<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel' => 'web',
            'external_participant_id' => fake()->uuid(),
            'status' => 'active',
        ];
    }
}
