<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Booking\BookingServiceResolver;
use App\Services\Booking\GoogleCalendarBookingService;
use App\Services\Booking\Google\GoogleCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class BookingServiceResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GoogleCalendar::class, new FakeGoogleCalendar());
    }

    public function test_returns_google_service_when_connected(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->googleAccount()->create([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
        ]);

        $service = app(BookingServiceResolver::class)->for($workspace->fresh());

        $this->assertInstanceOf(GoogleCalendarBookingService::class, $service);
    }

    public function test_returns_null_when_not_connected(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $this->assertNull(app(BookingServiceResolver::class)->for($workspace->fresh()));
    }
}
