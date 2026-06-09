<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Booking\BookingService;
use App\Services\Booking\FakeBookingService;
use App\Services\Bot\Claude\AnthropicClaudeClient;
use App\Services\Bot\Claude\ClaudeClient;
use Illuminate\Support\ServiceProvider;

class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap FakeBookingService for GoogleCalendarBookingService in sub-project 1b.
        $this->app->bind(BookingService::class, FakeBookingService::class);

        $this->app->bind(ClaudeClient::class, function () {
            $client = new Client(apiKey: (string) config('services.anthropic.key'));

            return new AnthropicClaudeClient($client);
        });
    }
}
