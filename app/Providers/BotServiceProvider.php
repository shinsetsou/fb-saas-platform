<?php

namespace App\Providers;

use App\Services\Booking\BookingService;
use App\Services\Booking\FakeBookingService;
use Illuminate\Support\ServiceProvider;

class BotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap FakeBookingService for GoogleCalendarBookingService in sub-project 1b.
        $this->app->bind(BookingService::class, FakeBookingService::class);
    }
}
