<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Booking\Google\GoogleApiCalendar;
use App\Services\Booking\Google\GoogleCalendar;
use App\Services\Bot\Claude\AnthropicClaudeClient;
use App\Services\Bot\Claude\ClaudeClient;
use App\Services\Channels\ConversationChannel;
use App\Services\Channels\WebChatChannel;
use App\Services\Facebook\FacebookGraph;
use App\Services\Facebook\HttpFacebookGraph;
use Illuminate\Support\ServiceProvider;

class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Booking is resolved per workspace via BookingServiceResolver; the gateway
        // backs the real GoogleCalendarBookingService.
        $this->app->bind(GoogleCalendar::class, GoogleApiCalendar::class);

        $this->app->bind(FacebookGraph::class, HttpFacebookGraph::class);

        $this->app->bind(ClaudeClient::class, function () {
            $client = new Client(apiKey: (string) config('services.anthropic.key'));

            return new AnthropicClaudeClient($client);
        });

        $this->app->bind(
            ConversationChannel::class,
            WebChatChannel::class,
        );
    }
}
