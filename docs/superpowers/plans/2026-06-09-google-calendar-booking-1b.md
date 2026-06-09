# Google Calendar Booking (Spec 1b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fake calendar with a real Google Calendar integration behind the existing `BookingService` interface, so connected workspaces book into the owner's primary calendar; unconnected workspaces cleanly hand off.

**Architecture:** OAuth (Laravel Socialite) connects a workspace's Google account, stored encrypted in `google_accounts`. A `GoogleCalendar` gateway interface isolates the `google/apiclient` SDK (real `GoogleApiCalendar`; `FakeGoogleCalendar` for tests). `GoogleCalendarBookingService` computes availability from `BotSetting` hours minus Google FreeBusy and writes events, mirroring each as a local `Appointment` (linked by `external_event_id`). A `BookingServiceResolver` returns the Google service for connected workspaces, `null` otherwise; `ToolRegistry` resolves per workspace and hands off when unconnected.

**Tech Stack:** Laravel 12, PHP 8.2, SQLite, `laravel/socialite`, `google/apiclient`, PHPUnit.

---

## Background: existing code this builds on (from spec 1a)

- `App\Services\Booking\BookingService` interface:
  `availability(Workspace $w, Carbon $day): array` (array of ISO-8601 strings),
  `book(Workspace $w, Carbon $startsAt, array $customer, ?int $conversationId = null): Appointment`,
  `find(Workspace $w, string $customerContact): Collection`,
  `reschedule(Appointment $a, Carbon $newStartsAt): Appointment`,
  `cancel(Appointment $a): void`.
- `App\Services\Booking\FakeBookingService` implements it (in-memory/DB slot logic).
- `App\Services\Bot\Tools\ToolRegistry` currently constructor-injects `BookingService` and
  calls it in `checkAvailability`/`bookAppointment`/`findBooking`/`rescheduleAppointment`/
  `cancelAppointment`. `request_human_handoff` sets `conversation.status = 'needs_human'`.
- `App\Providers\BotServiceProvider` binds `BookingService → FakeBookingService`,
  `ClaudeClient`, and `ConversationChannel`.
- `Appointment` model has `external_event_id` (nullable). `BotSetting` has
  `booking_timezone`, `booking_hours` (JSON, weekday → `[{start,end}]`),
  `default_appointment_duration_minutes`, `buffer_minutes`, `is_enabled`.
- Tests use `RefreshDatabase`, `User::factory()->create()`,
  `app(CreateWorkspaceForUser::class)->handle($user)`, real routes.

---

## File Structure

**New source files**
- `app/Models/GoogleAccount.php` — encrypted token record, one per workspace
- `database/migrations/2026_06_10_000001_create_google_accounts_table.php`
- `database/factories/GoogleAccountFactory.php`
- `app/Services/Booking/SlotGenerator.php` — shared hours→candidate-slots logic (DRY)
- `app/Services/Booking/Google/GoogleCalendar.php` — gateway interface
- `app/Services/Booking/Google/GoogleApiCalendar.php` — real gateway (google/apiclient)
- `app/Services/Booking/Google/GoogleClientFactory.php` — authed client + token refresh
- `app/Services/Booking/GoogleCalendarBookingService.php` — `BookingService` impl
- `app/Services/Booking/BookingServiceResolver.php` — per-workspace service resolution
- `app/Http/Controllers/GoogleCalendarController.php` — OAuth connect/callback/disconnect
- `tests/Support/FakeGoogleCalendar.php` — gateway test double

**Modified files**
- `composer.json` — add `laravel/socialite`, `google/apiclient`
- `config/services.php` — `google` block
- `.env.example` — Google OAuth env vars
- `app/Models/Workspace.php` — `googleAccount()` relation
- `app/Services/Booking/FakeBookingService.php` — use `SlotGenerator`
- `app/Services/Bot/Tools/ToolRegistry.php` — use `BookingServiceResolver` + handoff
- `app/Providers/BotServiceProvider.php` — bind `GoogleCalendar`; drop prod `BookingService` bind
- `app/Http/Controllers/DashboardController.php` — real `googleConnected`
- `resources/views/dashboard.blade.php` — connect/disconnect controls
- `routes/web.php` — google routes
- Updated tests: `ToolRegistryTest`, `BotEngineTest`, `DashboardTest`

**Shared type shapes (defined here, used throughout)**

- Candidate slot (from `SlotGenerator`): `['start' => Carbon, 'end' => Carbon]`.
- Busy interval (from gateway `freeBusy`): `['start' => Carbon, 'end' => Carbon]`.
- Event payload (to gateway `insertEvent`/`patchEvent`):
  `['summary' => string, 'start' => Carbon, 'end' => Carbon, 'attendee_email' => ?string]`.

---

## Task 1: Add Socialite and Google API client

**Files:** `composer.json`, `config/services.php`, `.env.example`

- [ ] **Step 1: Require packages**

Run: `composer require laravel/socialite google/apiclient`
Expected: both added to `composer.json` require; `composer.lock` updated. If network/sandbox blocks composer, retry the Bash call with `dangerouslyDisableSandbox: true`. If still blocked, report BLOCKED with the error — do not hand-edit composer files.

- [ ] **Step 2: Add the google config block**

In `config/services.php`, add inside the returned array:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

- [ ] **Step 3: Document env vars**

Append to `.env.example`:

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/google/callback"
```

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/services.php .env.example
git commit -m "chore: add socialite and google api client"
```

---

## Task 2: google_accounts table and model

**Files:**
- Create: `database/migrations/2026_06_10_000001_create_google_accounts_table.php`
- Create: `app/Models/GoogleAccount.php`, `database/factories/GoogleAccountFactory.php`
- Modify: `app/Models/Workspace.php`
- Test: `tests/Feature/GoogleAccountModelTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/GoogleAccountModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAccountModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_has_one_encrypted_google_account(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $account = $workspace->googleAccount()->create([
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-456',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
        ]);

        $this->assertSame('access-123', $workspace->fresh()->googleAccount->access_token);
        $this->assertSame('primary', $account->calendar_id);
        // Stored value is encrypted, not the raw token.
        $raw = \DB::table('google_accounts')->where('id', $account->id)->value('access_token');
        $this->assertNotSame('access-123', $raw);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter test_workspace_has_one_encrypted_google_account`
Expected: FAIL — `App\Models\GoogleAccount` not found.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_06_10_000001_create_google_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->dateTime('expires_at')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
```

- [ ] **Step 4: Write the model**

`app/Models/GoogleAccount.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleAccount extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'access_token', 'refresh_token', 'expires_at', 'calendar_id'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
```

- [ ] **Step 5: Add the Workspace relation**

In `app/Models/Workspace.php`, add to imports `use Illuminate\Database\Eloquent\Relations\HasOne;` (already imported for trial/botSetting — confirm; if present, skip) and add this method inside the class:

```php
public function googleAccount(): HasOne
{
    return $this->hasOne(GoogleAccount::class);
}
```

- [ ] **Step 6: Write the factory**

`database/factories/GoogleAccountFactory.php`:

```php
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
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter test_workspace_has_one_encrypted_google_account`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models/GoogleAccount.php app/Models/Workspace.php database/factories/GoogleAccountFactory.php tests/Feature/GoogleAccountModelTest.php
git commit -m "feat: add google account token model"
```

---

## Task 3: Extract SlotGenerator (DRY slot logic)

**Files:**
- Create: `app/Services/Booking/SlotGenerator.php`
- Modify: `app/Services/Booking/FakeBookingService.php`
- Test: `tests/Unit/SlotGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/SlotGeneratorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Booking\SlotGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_candidate_slots_from_hours(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'default_appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);

        // 2026-06-15 is a Monday.
        $slots = app(SlotGenerator::class)->generate($workspace->fresh()->botSetting, Carbon::parse('2026-06-15', 'UTC'));

        $this->assertCount(4, $slots);
        $this->assertEquals('2026-06-15T09:00:00+00:00', $slots[0]['start']->toIso8601String());
        $this->assertEquals('2026-06-15T09:30:00+00:00', $slots[0]['end']->toIso8601String());
    }

    public function test_closed_day_returns_no_slots(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);

        // 2026-06-16 is a Tuesday (no hours configured).
        $slots = app(SlotGenerator::class)->generate($workspace->fresh()->botSetting, Carbon::parse('2026-06-16', 'UTC'));

        $this->assertSame([], $slots);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SlotGeneratorTest`
Expected: FAIL — `App\Services\Booking\SlotGenerator` not found.

- [ ] **Step 3: Write SlotGenerator**

`app/Services/Booking/SlotGenerator.php`:

```php
<?php

namespace App\Services\Booking;

use App\Models\BotSetting;
use Carbon\Carbon;

class SlotGenerator
{
    /**
     * Candidate appointment slots for the given day, from the workspace's configured
     * booking hours, appointment duration, and buffer.
     *
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    public function generate(BotSetting $settings, Carbon $day): array
    {
        $tz = $settings->booking_timezone ?: 'UTC';
        $duration = (int) $settings->default_appointment_duration_minutes;
        $buffer = (int) $settings->buffer_minutes;
        $hours = $settings->booking_hours ?? [];

        $day = $day->copy()->setTimezone($tz)->startOfDay();
        $weekday = strtolower($day->format('l'));
        $ranges = $hours[$weekday] ?? [];

        $slots = [];
        foreach ($ranges as $range) {
            $cursor = $day->copy()->setTimeFromTimeString($range['start']);
            $end = $day->copy()->setTimeFromTimeString($range['end']);

            while ($cursor->copy()->addMinutes($duration)->lte($end)) {
                $slots[] = [
                    'start' => $cursor->copy(),
                    'end' => $cursor->copy()->addMinutes($duration),
                ];
                $cursor->addMinutes($duration + $buffer);
            }
        }

        return $slots;
    }
}
```

- [ ] **Step 4: Refactor FakeBookingService to use it**

Replace the body of `availability()` in `app/Services/Booking/FakeBookingService.php` so it uses `SlotGenerator`, and add the constructor. The full file becomes:

```php
<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FakeBookingService implements BookingService
{
    public function __construct(private SlotGenerator $slots) {}

    public function availability(Workspace $workspace, Carbon $day): array
    {
        $candidates = $this->slots->generate($workspace->botSetting, $day);
        if ($candidates === []) {
            return [];
        }

        $booked = $workspace->appointments()
            ->where('status', 'booked')
            ->whereDate('starts_at', $candidates[0]['start']->toDateString())
            ->get();

        $open = [];
        foreach ($candidates as $slot) {
            $conflict = $booked->first(fn (Appointment $a) => $slot['start']->lt($a->ends_at) && $slot['end']->gt($a->starts_at));
            if (! $conflict) {
                $open[] = $slot['start']->toIso8601String();
            }
        }

        return $open;
    }

    public function book(Workspace $workspace, Carbon $startsAt, array $customer, ?int $conversationId = null): Appointment
    {
        $duration = (int) $workspace->botSetting->default_appointment_duration_minutes;

        return $workspace->appointments()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversationId,
            'customer_name' => $customer['name'],
            'customer_contact' => $customer['contact'],
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($duration),
            'status' => 'booked',
        ]);
    }

    public function find(Workspace $workspace, string $customerContact): Collection
    {
        return $workspace->appointments()
            ->where('customer_contact', $customerContact)
            ->where('status', 'booked')
            ->orderBy('starts_at')
            ->get();
    }

    public function reschedule(Appointment $appointment, Carbon $newStartsAt): Appointment
    {
        $duration = (int) $appointment->workspace->botSetting->default_appointment_duration_minutes;
        $appointment->update([
            'starts_at' => $newStartsAt,
            'ends_at' => $newStartsAt->copy()->addMinutes($duration),
        ]);

        return $appointment->fresh();
    }

    public function cancel(Appointment $appointment): void
    {
        $appointment->update(['status' => 'cancelled']);
    }
}
```

- [ ] **Step 5: Run the relevant tests to verify they pass**

Run: `php artisan test --filter "SlotGeneratorTest|FakeBookingServiceTest"`
Expected: PASS — the new SlotGenerator tests and the existing `FakeBookingServiceTest` (unchanged) both green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Booking/SlotGenerator.php app/Services/Booking/FakeBookingService.php tests/Unit/SlotGeneratorTest.php
git commit -m "refactor: extract slot generator shared by booking services"
```

---

## Task 4: GoogleCalendar gateway interface + fake

**Files:**
- Create: `app/Services/Booking/Google/GoogleCalendar.php`
- Create: `tests/Support/FakeGoogleCalendar.php`
- Test: `tests/Unit/FakeGoogleCalendarTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FakeGoogleCalendarTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\GoogleAccount;
use Carbon\Carbon;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class FakeGoogleCalendarTest extends TestCase
{
    public function test_records_calls_and_returns_scripted_busy(): void
    {
        $account = new GoogleAccount(['calendar_id' => 'primary']);
        $calendar = new FakeGoogleCalendar(busy: [
            ['start' => Carbon::parse('2026-06-15T09:00:00+00:00'), 'end' => Carbon::parse('2026-06-15T09:30:00+00:00')],
        ]);

        $busy = $calendar->freeBusy($account, Carbon::parse('2026-06-15T00:00:00+00:00'), Carbon::parse('2026-06-15T23:59:59+00:00'));
        $eventId = $calendar->insertEvent($account, [
            'summary' => 'Appointment: Sam',
            'start' => Carbon::parse('2026-06-15T10:00:00+00:00'),
            'end' => Carbon::parse('2026-06-15T10:30:00+00:00'),
            'attendee_email' => 'sam@example.com',
        ]);

        $this->assertCount(1, $busy);
        $this->assertSame('fake-event-1', $eventId);
        $this->assertCount(1, $calendar->inserted);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter FakeGoogleCalendarTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the interface**

`app/Services/Booking/Google/GoogleCalendar.php`:

```php
<?php

namespace App\Services\Booking\Google;

use App\Models\GoogleAccount;
use Carbon\Carbon;

interface GoogleCalendar
{
    /**
     * Busy intervals on the account's calendar within [$from, $to].
     *
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    public function freeBusy(GoogleAccount $account, Carbon $from, Carbon $to): array;

    /**
     * Create an event; returns the Google event id.
     *
     * @param array{summary: string, start: Carbon, end: Carbon, attendee_email: ?string} $event
     */
    public function insertEvent(GoogleAccount $account, array $event): string;

    /**
     * @param array{summary: string, start: Carbon, end: Carbon, attendee_email: ?string} $event
     */
    public function patchEvent(GoogleAccount $account, string $eventId, array $event): void;

    public function deleteEvent(GoogleAccount $account, string $eventId): void;
}
```

- [ ] **Step 4: Write the fake**

`tests/Support/FakeGoogleCalendar.php`:

```php
<?php

namespace Tests\Support;

use App\Models\GoogleAccount;
use App\Services\Booking\Google\GoogleCalendar;
use Carbon\Carbon;

class FakeGoogleCalendar implements GoogleCalendar
{
    /** @var array<int, array{start: Carbon, end: Carbon}> */
    public array $busy;

    /** @var array<int, array<string, mixed>> */
    public array $inserted = [];

    /** @var array<int, array<string, mixed>> */
    public array $patched = [];

    /** @var array<int, string> */
    public array $deleted = [];

    private int $counter = 0;

    /** @param array<int, array{start: Carbon, end: Carbon}> $busy */
    public function __construct(array $busy = [])
    {
        $this->busy = $busy;
    }

    public function freeBusy(GoogleAccount $account, Carbon $from, Carbon $to): array
    {
        return $this->busy;
    }

    public function insertEvent(GoogleAccount $account, array $event): string
    {
        $this->inserted[] = $event;

        return 'fake-event-'.(++$this->counter);
    }

    public function patchEvent(GoogleAccount $account, string $eventId, array $event): void
    {
        $this->patched[] = ['id' => $eventId] + $event;
    }

    public function deleteEvent(GoogleAccount $account, string $eventId): void
    {
        $this->deleted[] = $eventId;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter FakeGoogleCalendarTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Booking/Google/GoogleCalendar.php tests/Support/FakeGoogleCalendar.php tests/Unit/FakeGoogleCalendarTest.php
git commit -m "feat: add google calendar gateway interface and fake"
```

---

## Task 5: GoogleCalendarBookingService

**Files:**
- Create: `app/Services/Booking/GoogleCalendarBookingService.php`
- Test: `tests/Feature/GoogleCalendarBookingServiceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/GoogleCalendarBookingServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Booking\GoogleCalendarBookingService;
use App\Services\Booking\SlotGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class GoogleCalendarBookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function connectedWorkspace(): \App\Models\Workspace
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'default_appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);
        $workspace->googleAccount()->create([
            'access_token' => 'a', 'refresh_token' => 'r',
            'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
        ]);

        return $workspace->fresh();
    }

    public function test_availability_excludes_busy_intervals(): void
    {
        $workspace = $this->connectedWorkspace();
        $calendar = new FakeGoogleCalendar(busy: [
            ['start' => Carbon::parse('2026-06-15T09:00:00+00:00'), 'end' => Carbon::parse('2026-06-15T09:30:00+00:00')],
        ]);
        $service = new GoogleCalendarBookingService($calendar, app(SlotGenerator::class));

        $slots = $service->availability($workspace, Carbon::parse('2026-06-15', 'UTC'));

        $this->assertNotContains('2026-06-15T09:00:00+00:00', $slots);
        $this->assertCount(3, $slots);
    }

    public function test_book_inserts_event_and_stores_external_id(): void
    {
        $workspace = $this->connectedWorkspace();
        $calendar = new FakeGoogleCalendar();
        $service = new GoogleCalendarBookingService($calendar, app(SlotGenerator::class));

        $appointment = $service->book(
            $workspace,
            Carbon::parse('2026-06-15T09:00:00+00:00'),
            ['name' => 'Sam', 'contact' => 'sam@example.com'],
        );

        $this->assertCount(1, $calendar->inserted);
        $this->assertSame('fake-event-1', $appointment->external_event_id);
        $this->assertDatabaseHas('appointments', ['external_event_id' => 'fake-event-1', 'status' => 'booked']);
    }

    public function test_cancel_deletes_event_and_marks_cancelled(): void
    {
        $workspace = $this->connectedWorkspace();
        $calendar = new FakeGoogleCalendar();
        $service = new GoogleCalendarBookingService($calendar, app(SlotGenerator::class));
        $appointment = $service->book($workspace, Carbon::parse('2026-06-15T09:00:00+00:00'), ['name' => 'Sam', 'contact' => 'sam@example.com']);

        $service->cancel($appointment);

        $this->assertContains('fake-event-1', $calendar->deleted);
        $this->assertSame('cancelled', $appointment->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GoogleCalendarBookingServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/Booking/GoogleCalendarBookingService.php`:

```php
<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use App\Services\Booking\Google\GoogleCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GoogleCalendarBookingService implements BookingService
{
    public function __construct(
        private GoogleCalendar $calendar,
        private SlotGenerator $slots,
    ) {}

    public function availability(Workspace $workspace, Carbon $day): array
    {
        $candidates = $this->slots->generate($workspace->botSetting, $day);
        if ($candidates === []) {
            return [];
        }

        $account = $workspace->googleAccount;
        $from = $candidates[0]['start'];
        $to = $candidates[count($candidates) - 1]['end'];
        $busy = $this->calendar->freeBusy($account, $from, $to);

        $open = [];
        foreach ($candidates as $slot) {
            $conflict = false;
            foreach ($busy as $interval) {
                if ($slot['start']->lt($interval['end']) && $slot['end']->gt($interval['start'])) {
                    $conflict = true;
                    break;
                }
            }
            if (! $conflict) {
                $open[] = $slot['start']->toIso8601String();
            }
        }

        return $open;
    }

    public function book(Workspace $workspace, Carbon $startsAt, array $customer, ?int $conversationId = null): Appointment
    {
        $account = $workspace->googleAccount;
        $duration = (int) $workspace->botSetting->default_appointment_duration_minutes;
        $endsAt = $startsAt->copy()->addMinutes($duration);

        $eventId = $this->calendar->insertEvent($account, [
            'summary' => "Appointment: {$customer['name']}",
            'start' => $startsAt,
            'end' => $endsAt,
            'attendee_email' => str_contains($customer['contact'], '@') ? $customer['contact'] : null,
        ]);

        return $workspace->appointments()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversationId,
            'customer_name' => $customer['name'],
            'customer_contact' => $customer['contact'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'booked',
            'external_event_id' => $eventId,
        ]);
    }

    public function find(Workspace $workspace, string $customerContact): Collection
    {
        return $workspace->appointments()
            ->where('customer_contact', $customerContact)
            ->where('status', 'booked')
            ->orderBy('starts_at')
            ->get();
    }

    public function reschedule(Appointment $appointment, Carbon $newStartsAt): Appointment
    {
        $workspace = $appointment->workspace;
        $duration = (int) $workspace->botSetting->default_appointment_duration_minutes;
        $endsAt = $newStartsAt->copy()->addMinutes($duration);

        $this->calendar->patchEvent($workspace->googleAccount, $appointment->external_event_id, [
            'summary' => "Appointment: {$appointment->customer_name}",
            'start' => $newStartsAt,
            'end' => $endsAt,
            'attendee_email' => str_contains($appointment->customer_contact, '@') ? $appointment->customer_contact : null,
        ]);

        $appointment->update(['starts_at' => $newStartsAt, 'ends_at' => $endsAt]);

        return $appointment->fresh();
    }

    public function cancel(Appointment $appointment): void
    {
        $this->calendar->deleteEvent($appointment->workspace->googleAccount, $appointment->external_event_id);
        $appointment->update(['status' => 'cancelled']);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter GoogleCalendarBookingServiceTest`
Expected: PASS (all three tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Booking/GoogleCalendarBookingService.php tests/Feature/GoogleCalendarBookingServiceTest.php
git commit -m "feat: add google calendar booking service"
```

---

## Task 6: BookingServiceResolver

**Files:**
- Create: `app/Services/Booking/BookingServiceResolver.php`
- Test: `tests/Feature/BookingServiceResolverTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/BookingServiceResolverTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BookingServiceResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the resolver**

`app/Services/Booking/BookingServiceResolver.php`:

```php
<?php

namespace App\Services\Booking;

use App\Models\Workspace;
use App\Services\Booking\Google\GoogleCalendar;

class BookingServiceResolver
{
    public function __construct(
        private GoogleCalendar $calendar,
        private SlotGenerator $slots,
    ) {}

    public function for(Workspace $workspace): ?BookingService
    {
        if (! $workspace->googleAccount()->exists()) {
            return null;
        }

        return new GoogleCalendarBookingService($this->calendar, $this->slots);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter BookingServiceResolverTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Booking/BookingServiceResolver.php tests/Feature/BookingServiceResolverTest.php
git commit -m "feat: add per-workspace booking service resolver"
```

---

## Task 7: Refactor ToolRegistry to resolve per workspace + handoff

**Files:**
- Modify: `app/Services/Bot/Tools/ToolRegistry.php`
- Modify: `tests/Feature/ToolRegistryTest.php`

The not-connected reply text is a single constant reused by every booking handler.

- [ ] **Step 1: Update the test**

Replace `tests/Feature/ToolRegistryTest.php` with (note: `definitions()` is unchanged; booking now requires a connected workspace + a fake gateway, and there is a new not-connected case):

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\Google\GoogleCalendar;
use App\Services\Bot\Tools\ToolContext;
use App\Services\Bot\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleCalendar;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GoogleCalendar::class, new FakeGoogleCalendar());
    }

    private function context(bool $connected): ToolContext
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        if ($connected) {
            $workspace->googleAccount()->create([
                'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
            ]);
        }
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => 'web',
            'external_participant_id' => 'tester', 'status' => 'active',
        ]);

        return new ToolContext($workspace->fresh(), $conversation);
    }

    public function test_definitions_expose_six_tools(): void
    {
        $names = array_column(app(ToolRegistry::class)->definitions(), 'name');

        $this->assertEqualsCanonicalizing([
            'check_availability', 'book_appointment', 'find_booking',
            'reschedule_appointment', 'cancel_appointment', 'request_human_handoff',
        ], $names);
    }

    public function test_book_appointment_creates_record_when_connected(): void
    {
        $context = $this->context(connected: true);

        $result = app(ToolRegistry::class)->execute('book_appointment', [
            'starts_at' => '2026-06-15T09:00:00+00:00',
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
        ], $context);

        $this->assertDatabaseHas('appointments', ['customer_contact' => 'sam@example.com', 'status' => 'booked']);
        $this->assertStringContainsString('booked', strtolower($result));
    }

    public function test_booking_tool_hands_off_when_not_connected(): void
    {
        $context = $this->context(connected: false);

        $result = app(ToolRegistry::class)->execute('book_appointment', [
            'starts_at' => '2026-06-15T09:00:00+00:00',
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
        ], $context);

        $this->assertStringContainsString("isn't connected", $result);
        $this->assertSame('needs_human', $context->conversation->fresh()->status);
        $this->assertDatabaseMissing('appointments', ['customer_contact' => 'sam@example.com']);
    }

    public function test_handoff_marks_conversation(): void
    {
        $context = $this->context(connected: false);

        app(ToolRegistry::class)->execute('request_human_handoff', ['reason' => 'angry customer'], $context);

        $this->assertSame('needs_human', $context->conversation->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ToolRegistryTest`
Expected: FAIL — `ToolRegistry` still expects a `BookingService`, and there is no not-connected handling yet.

- [ ] **Step 3: Refactor ToolRegistry**

In `app/Services/Bot/Tools/ToolRegistry.php`:

1. Change the import and constructor from `BookingService` to the resolver:

```php
use App\Services\Booking\BookingService;
use App\Services\Booking\BookingServiceResolver;
```

```php
private const NOT_CONNECTED = "The calendar isn't connected yet — a teammate will follow up.";

public function __construct(private BookingServiceResolver $resolver) {}
```

2. Add a private helper that resolves or hands off. Add this method to the class:

```php
private function resolveOrHandoff(ToolContext $context): ?BookingService
{
    $service = $this->resolver->for($context->workspace);
    if ($service === null) {
        $context->conversation->update(['status' => 'needs_human']);
    }

    return $service;
}
```

3. Update each booking handler to resolve first and bail with `self::NOT_CONNECTED` when null. Replace the five booking handlers with:

```php
private function checkAvailability(array $input, ToolContext $context): string
{
    if (! $booking = $this->resolveOrHandoff($context)) {
        return self::NOT_CONNECTED;
    }
    $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
    $day = Carbon::parse($input['date'], $tz);
    $slots = $booking->availability($context->workspace, $day);

    return $slots === [] ? 'No open slots on that date.' : 'Open slots: '.implode(', ', $slots);
}

private function bookAppointment(array $input, ToolContext $context): string
{
    if (! $booking = $this->resolveOrHandoff($context)) {
        return self::NOT_CONNECTED;
    }
    $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
    $appointment = $booking->book(
        $context->workspace,
        Carbon::parse($input['starts_at'], $tz),
        ['name' => $input['customer_name'], 'contact' => $input['customer_contact']],
        $context->conversation->id,
    );

    return "Appointment booked (id {$appointment->id}) for {$appointment->starts_at->toIso8601String()}.";
}

private function findBooking(array $input, ToolContext $context): string
{
    if (! $booking = $this->resolveOrHandoff($context)) {
        return self::NOT_CONNECTED;
    }
    $found = $booking->find($context->workspace, $input['customer_contact']);
    if ($found->isEmpty()) {
        return 'No booked appointments found for that contact.';
    }

    return $found->map(fn ($a) => "id {$a->id}: {$a->starts_at->toIso8601String()}")->implode('; ');
}

private function rescheduleAppointment(array $input, ToolContext $context): string
{
    if (! $booking = $this->resolveOrHandoff($context)) {
        return self::NOT_CONNECTED;
    }
    $appointment = $context->workspace->appointments()->find($input['appointment_id']);
    if (! $appointment) {
        return 'Appointment not found.';
    }
    $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
    $updated = $booking->reschedule($appointment, Carbon::parse($input['new_starts_at'], $tz));

    return "Rescheduled to {$updated->starts_at->toIso8601String()}.";
}

private function cancelAppointment(array $input, ToolContext $context): string
{
    if (! $booking = $this->resolveOrHandoff($context)) {
        return self::NOT_CONNECTED;
    }
    $appointment = $context->workspace->appointments()->find($input['appointment_id']);
    if (! $appointment) {
        return 'Appointment not found.';
    }
    $booking->cancel($appointment);

    return 'Appointment cancelled.';
}
```

Leave `definitions()`, `execute()`, and `requestHumanHandoff()` unchanged. Keep the `use Carbon\Carbon;` import.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter ToolRegistryTest`
Expected: PASS (all four tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bot/Tools/ToolRegistry.php tests/Feature/ToolRegistryTest.php
git commit -m "refactor: resolve booking service per workspace with handoff"
```

---

## Task 8: GoogleClientFactory (real token management)

**Files:**
- Create: `app/Services/Booking/Google/GoogleClientFactory.php`

> This wraps live OAuth token refresh and is exercised at runtime, not unit tested (refresh hits Google's token endpoint). Before writing, confirm the `google/apiclient` client API (`Google\Client`, `setAccessToken`, `isAccessTokenExpired`, `fetchAccessTokenWithRefreshToken`, `getAccessToken`) against the installed package under `vendor/google/apiclient`.

- [ ] **Step 1: Write the factory**

`app/Services/Booking/Google/GoogleClientFactory.php`:

```php
<?php

namespace App\Services\Booking\Google;

use App\Models\GoogleAccount;
use Carbon\Carbon;
use Google\Client;

class GoogleClientFactory
{
    public function for(GoogleAccount $account): Client
    {
        $client = new Client();
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->expires_at
                ? max(0, $account->expires_at->diffInSeconds(now(), false) * -1)
                : 0,
            'created' => now()->subSeconds(0)->timestamp,
        ]);

        if ($client->isAccessTokenExpired() && $account->refresh_token) {
            $new = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            if (! isset($new['error'])) {
                $account->update([
                    'access_token' => $new['access_token'],
                    'expires_at' => Carbon::now()->addSeconds((int) ($new['expires_in'] ?? 3600)),
                ]);
            }
        }

        return $client;
    }
}
```

- [ ] **Step 2: Verify it lints**

Run: `php -l app/Services/Booking/Google/GoogleClientFactory.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add app/Services/Booking/Google/GoogleClientFactory.php
git commit -m "feat: add google client factory with token refresh"
```

---

## Task 9: GoogleApiCalendar (real gateway)

**Files:**
- Create: `app/Services/Booking/Google/GoogleApiCalendar.php`

> Real gateway over `google/apiclient`'s `Google\Service\Calendar`. Runtime only, not unit tested. Before writing, confirm the Calendar service classes against `vendor/google/apiclient-services/src/Calendar` (`Google\Service\Calendar`, `FreeBusyRequest`, `FreeBusyRequestItem`, `Event`, `EventDateTime`, `EventAttendee`). Adjust class/method names to match the installed version if they differ.

- [ ] **Step 1: Write the gateway**

`app/Services/Booking/Google/GoogleApiCalendar.php`:

```php
<?php

namespace App\Services\Booking\Google;

use App\Models\GoogleAccount;
use Carbon\Carbon;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;

class GoogleApiCalendar implements GoogleCalendar
{
    public function __construct(private GoogleClientFactory $clients) {}

    public function freeBusy(GoogleAccount $account, Carbon $from, Carbon $to): array
    {
        $service = new Calendar($this->clients->for($account));

        $request = new FreeBusyRequest();
        $request->setTimeMin($from->toRfc3339String());
        $request->setTimeMax($to->toRfc3339String());
        $item = new FreeBusyRequestItem();
        $item->setId($account->calendar_id);
        $request->setItems([$item]);

        $response = $service->freebusy->query($request);
        $calendars = $response->getCalendars();
        $busyPeriods = $calendars[$account->calendar_id]->getBusy() ?? [];

        $busy = [];
        foreach ($busyPeriods as $period) {
            $busy[] = [
                'start' => Carbon::parse($period->getStart()),
                'end' => Carbon::parse($period->getEnd()),
            ];
        }

        return $busy;
    }

    public function insertEvent(GoogleAccount $account, array $event): string
    {
        $service = new Calendar($this->clients->for($account));
        $created = $service->events->insert($account->calendar_id, $this->toEvent($event));

        return $created->getId();
    }

    public function patchEvent(GoogleAccount $account, string $eventId, array $event): void
    {
        $service = new Calendar($this->clients->for($account));
        $service->events->patch($account->calendar_id, $eventId, $this->toEvent($event));
    }

    public function deleteEvent(GoogleAccount $account, string $eventId): void
    {
        $service = new Calendar($this->clients->for($account));
        $service->events->delete($account->calendar_id, $eventId);
    }

    /** @param array{summary: string, start: Carbon, end: Carbon, attendee_email: ?string} $event */
    private function toEvent(array $event): Event
    {
        $start = new EventDateTime();
        $start->setDateTime($event['start']->toRfc3339String());
        $end = new EventDateTime();
        $end->setDateTime($event['end']->toRfc3339String());

        $google = new Event();
        $google->setSummary($event['summary']);
        $google->setStart($start);
        $google->setEnd($end);

        if (! empty($event['attendee_email'])) {
            $attendee = new EventAttendee();
            $attendee->setEmail($event['attendee_email']);
            $google->setAttendees([$attendee]);
        }

        return $google;
    }
}
```

- [ ] **Step 2: Verify it lints**

Run: `php -l app/Services/Booking/Google/GoogleApiCalendar.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add app/Services/Booking/Google/GoogleApiCalendar.php
git commit -m "feat: add real google calendar gateway"
```

---

## Task 10: Wire bindings in BotServiceProvider

**Files:**
- Modify: `app/Providers/BotServiceProvider.php`

- [ ] **Step 1: Update the provider**

In `app/Providers/BotServiceProvider.php`:

1. Remove the `BookingService → FakeBookingService` binding line and its now-unused imports (`use App\Services\Booking\BookingService;`, `use App\Services\Booking\FakeBookingService;`).
2. Add the gateway binding. Add these imports:

```php
use App\Services\Booking\Google\GoogleApiCalendar;
use App\Services\Booking\Google\GoogleCalendar;
```

3. Add to `register()`:

```php
$this->app->bind(GoogleCalendar::class, GoogleApiCalendar::class);
```

`BookingServiceResolver` and `GoogleCalendarBookingService` are autowired (their deps — `GoogleCalendar`, `SlotGenerator` — are resolvable), so no explicit binding is needed for them. Keep the existing `ClaudeClient` and `ConversationChannel` bindings.

- [ ] **Step 2: Verify resolution + that nothing regressed**

Run: `php artisan config:clear && php artisan test --filter "ToolRegistryTest|BookingServiceResolverTest"`
Expected: PASS — the resolver binding and gateway resolve correctly. (These tests bind a `FakeGoogleCalendar` over the real one in their `setUp`.)

- [ ] **Step 3: Commit**

```bash
git add app/Providers/BotServiceProvider.php
git commit -m "feat: bind google calendar gateway, drop fake booking binding"
```

---

## Task 11: Update BotEngine booking test for connected workspace

**Files:**
- Modify: `tests/Feature/BotEngineTest.php`

The 1a booking-flow test assumed a `BookingService` binding. Booking now needs a connected workspace + a fake gateway. The engine itself does not change.

- [ ] **Step 1: Update the test**

In `tests/Feature/BotEngineTest.php`:

1. Add imports:

```php
use App\Services\Booking\Google\GoogleCalendar;
use Tests\Support\FakeGoogleCalendar;
```

2. Add a `setUp` that binds the fake gateway:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->app->instance(GoogleCalendar::class, new FakeGoogleCalendar());
}
```

3. In the `conversation()` helper, connect Google after enabling the bot, so booking resolves:

```php
$workspace->botSetting->update(['is_enabled' => true]);
$workspace->googleAccount()->create([
    'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
]);
```

Leave `test_engine_runs_tool_then_returns_text` assertions otherwise unchanged (it still books Sam and expects the final text), and `test_disabled_bot_does_not_reply` unchanged.

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test --filter BotEngineTest`
Expected: PASS (both tests) — booking now flows through the fake gateway.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/BotEngineTest.php
git commit -m "test: connect google in bot engine booking test"
```

---

## Task 12: OAuth connect/callback/disconnect

**Files:**
- Create: `app/Http/Controllers/GoogleCalendarController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/GoogleCalendarConnectionTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/GoogleCalendarConnectionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Mockery;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_stores_google_account(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $socialiteUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $socialiteUser->token = 'access-xyz';
        $socialiteUser->refreshToken = 'refresh-xyz';
        $socialiteUser->expiresIn = 3600;

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        $this->mock(Socialite::class)->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)
            ->get('/google/callback?code=test-code')
            ->assertRedirect('/dashboard');

        $this->assertDatabaseHas('google_accounts', ['workspace_id' => $workspace->id, 'calendar_id' => 'primary']);
        $this->assertSame('access-xyz', $workspace->fresh()->googleAccount->access_token);
    }

    public function test_disconnect_removes_account(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->googleAccount()->create([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
        ]);

        $this->actingAs($user)
            ->delete('/google/disconnect')
            ->assertRedirect('/dashboard');

        $this->assertDatabaseMissing('google_accounts', ['workspace_id' => $workspace->id]);
    }

    public function test_connect_redirects_to_google(): void
    {
        $user = User::factory()->create();
        app(CreateWorkspaceForUser::class)->handle($user);

        $provider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        $this->mock(Socialite::class)->shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)
            ->get('/google/connect')
            ->assertRedirectContains('accounts.google.com');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GoogleCalendarConnectionTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/GoogleCalendarController.php`:

```php
<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GoogleCalendarController extends Controller
{
    public function connect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/calendar.readonly',
            ])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('dashboard')->with('status', 'google-connect-failed');
        }

        $googleUser = Socialite::driver('google')->user();
        $workspace = $request->user()->workspaces()->firstOrFail();

        $workspace->googleAccount()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'access_token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken ?? $workspace->googleAccount?->refresh_token ?? '',
                'expires_at' => Carbon::now()->addSeconds((int) ($googleUser->expiresIn ?? 3600)),
                'calendar_id' => 'primary',
            ],
        );

        return redirect()->route('dashboard')->with('status', 'google-connected');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $workspace = $request->user()->workspaces()->firstOrFail();
        $workspace->googleAccount()->delete();

        return redirect()->route('dashboard')->with('status', 'google-disconnected');
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add `use App\Http\Controllers\GoogleCalendarController;` and, inside the existing `Route::middleware(['auth', 'verified'])->group(...)` block:

```php
Route::get('/google/connect', [GoogleCalendarController::class, 'connect'])->name('google.connect');
Route::get('/google/callback', [GoogleCalendarController::class, 'callback'])->name('google.callback');
Route::delete('/google/disconnect', [GoogleCalendarController::class, 'disconnect'])->name('google.disconnect');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter GoogleCalendarConnectionTest`
Expected: PASS (all three tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/GoogleCalendarController.php routes/web.php tests/Feature/GoogleCalendarConnectionTest.php
git commit -m "feat: add google calendar oauth connect flow"
```

---

## Task 13: Dashboard connection state + controls

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/views/dashboard.blade.php`
- Modify: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Update the test**

In `tests/Feature/DashboardTest.php`, the existing test asserts `assertSee('Google Calendar not connected')`. Add a second test for the connected state. Append this method to the class:

```php
public function test_dashboard_shows_google_connected_when_account_exists(): void
{
    $user = User::factory()->create(['name' => 'Morgan Owner']);
    $workspace = app(CreateWorkspaceForUser::class)->handle($user);
    $workspace->googleAccount()->create([
        'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary',
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Google Calendar connected');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter DashboardTest`
Expected: FAIL — `test_dashboard_shows_google_connected_when_account_exists` fails because `googleConnected` is hardcoded `false`.

- [ ] **Step 3: Wire the controller**

In `app/Http/Controllers/DashboardController.php`, change the `googleConnected` value from the hardcoded `false` to a real check. The `$workspace` is already loaded; update the view data:

```php
'googleConnected' => $workspace->googleAccount()->exists(),
```

(Leave `facebookConnected => false` as-is.)

- [ ] **Step 4: Update the view**

In `resources/views/dashboard.blade.php`, find where the Google status is rendered (it currently prints "Google Calendar not connected" using the `$googleConnected` variable). Ensure it renders "Google Calendar connected" when true and "Google Calendar not connected" when false, and add connect/disconnect controls. Use this block where the Google status is shown:

```blade
@if ($googleConnected)
    <p class="text-sm text-green-700">Google Calendar connected</p>
    <form method="POST" action="{{ route('google.disconnect') }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-sm text-red-600 underline">Disconnect</button>
    </form>
@else
    <p class="text-sm text-gray-600">Google Calendar not connected</p>
    <a href="{{ route('google.connect') }}" class="text-sm text-indigo-600 underline">Connect Google Calendar</a>
@endif
```

If the existing markup already prints the not-connected string in a different structure, replace that specific element with the block above rather than duplicating it.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter DashboardTest`
Expected: PASS (both the original and the new test).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: show real google connection state on dashboard"
```

---

## Task 14: Full-suite green and format

**Files:** none (verification only)

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: all tests pass, including the 1a suite (engine, tools, channel, job, chat tester) and the pre-existing auth/workspace/dashboard tests.

- [ ] **Step 2: Format**

Run: `./vendor/bin/pint`
Expected: files formatted, no errors.

- [ ] **Step 3: Commit any formatting changes**

```bash
git add -A
git commit -m "style: pint formatting"
```

---

## Self-Review notes

- **Spec coverage:** token storage → Task 2; OAuth connect/callback/disconnect → Task 12;
  token refresh → Task 8; FreeBusy availability + event CRUD → Tasks 4/5/9; local
  `Appointment` mirror via `external_event_id` → Task 5; per-workspace resolver + handoff →
  Tasks 6/7; gateway isolation seam + fake → Task 4; dashboard wiring → Task 13; DRY slot
  logic (open item resolved by extracting `SlotGenerator`) → Task 3. All spec sections map
  to a task.
- **Test-wiring consequences handled:** the 1a `ToolRegistryTest` and `BotEngineTest`
  assumed a single `BookingService` binding; Tasks 7 and 11 update them to connect Google +
  bind `FakeGoogleCalendar`. Without these the 1a tests would break after the resolver
  refactor — they are explicit tasks, not afterthoughts.
- **Type consistency:** the gateway interface (`freeBusy`/`insertEvent`/`patchEvent`/
  `deleteEvent`) and the slot/busy/event array shapes are identical across the interface
  (Task 4), the fake (Task 4), `GoogleCalendarBookingService` (Task 5), and
  `GoogleApiCalendar` (Task 9). `BookingServiceResolver::for(): ?BookingService` (Task 6) is
  consumed unchanged in `ToolRegistry` (Task 7). `SlotGenerator::generate()` (Task 3) is
  used by both booking services with the same `['start','end']` shape.
- **Runtime-only, un-unit-tested seams (by design, flagged in spec):** `GoogleClientFactory`
  (Task 8) and `GoogleApiCalendar` (Task 9) wrap live Google calls; both include a
  "confirm against the installed package" instruction and a lint check. All booking logic
  is unit-tested through `FakeGoogleCalendar`.
- **Placeholder scan:** no TBD/TODO; every code step shows complete code; the two
  runtime-only tasks intentionally have no unit test, which the steps state explicitly with
  a lint verification instead.
