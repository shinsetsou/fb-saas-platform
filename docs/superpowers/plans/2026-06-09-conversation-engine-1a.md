# Conversation Engine (Spec 1a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a channel-agnostic, queued conversation engine that lets a customer book/reschedule/cancel appointments, ask FAQs, or be handed off to a human — driven by Claude tool-use, backed by a fake in-memory calendar, and testable end-to-end through a local web-chat page.

**Architecture:** An incoming message is dispatched to a queued `ProcessIncomingMessage` job that resolves a `Conversation`, runs `BotEngine`, and sends the reply through a `ConversationChannel`. `BotEngine` runs a Claude tool-use loop; the six tools call a `BookingService` (the `FakeBookingService` in 1a; `GoogleCalendarBookingService` swaps in for 1b behind the same interface). The Claude API is isolated behind a `ClaudeClient` interface so tests bind a deterministic fake and never hit the network.

**Tech Stack:** Laravel 12, PHP 8.2, SQLite, `anthropic-ai/sdk` (Claude, model `claude-opus-4-8`), PHPUnit, Blade + Alpine + Tailwind v4.

---

## File Structure

**New source files**
- `app/Models/Conversation.php` — conversation aggregate (belongs to workspace, has messages + appointments)
- `app/Models/Message.php` — one turn (user/assistant/tool) in a conversation
- `app/Models/Appointment.php` — a booked slot
- `app/Services/Bot/BotEngine.php` — runs the Claude tool-use loop
- `app/Services/Bot/SystemPromptBuilder.php` — builds the system prompt from a `BotSetting`
- `app/Services/Bot/Claude/ClaudeClient.php` — interface: one `createMessage(array): array` call
- `app/Services/Bot/Claude/AnthropicClaudeClient.php` — real implementation (SDK)
- `app/Services/Bot/Tools/ToolRegistry.php` — tool JSON schemas + dispatch to handlers
- `app/Services/Bot/Tools/ToolContext.php` — value object carrying `Workspace` + `Conversation`
- `app/Services/Booking/BookingService.php` — interface
- `app/Services/Booking/FakeBookingService.php` — availability from BotSetting hours minus booked appts
- `app/Services/Channels/ConversationChannel.php` — interface
- `app/Services/Channels/WebChatChannel.php` — persists assistant reply for the web UI
- `app/Jobs/ProcessIncomingMessage.php` — queued entry point
- `app/Http/Controllers/ChatTesterController.php` — local web-chat tester
- `app/Providers/BotServiceProvider.php` — binds interfaces to implementations
- `database/factories/ConversationFactory.php`, `MessageFactory.php`, `AppointmentFactory.php`
- `database/migrations/*_create_conversations_table.php`, `*_create_messages_table.php`, `*_create_appointments_table.php`
- `resources/views/chat-tester/index.blade.php`
- `tests/Support/FakeClaudeClient.php` — scripted Claude responses for tests

**Modified files**
- `composer.json` — add `anthropic-ai/sdk`
- `config/services.php` — Anthropic key + model config
- `.env.example` — `ANTHROPIC_API_KEY`
- `bootstrap/providers.php` — register `BotServiceProvider`
- `routes/web.php` — chat-tester routes
- `app/Models/Workspace.php` — add `conversations()` / `appointments()` relations

---

## Conventions (read before starting)

- Domain logic lives in service/action classes (mirrors `App\Actions\Workspaces\CreateWorkspaceForUser`), not controllers.
- Active workspace resolved via `$request->user()->workspaces()->...->firstOrFail()` (see `DashboardController`).
- Feature tests use `RefreshDatabase`, create a user with `User::factory()->create()`, and a workspace with `app(CreateWorkspaceForUser::class)->handle($user)` — match `tests/Feature/BotSettingsTest.php`.
- Run a single test: `php artisan test --filter test_name`. Run all: `php artisan test`. Format: `./vendor/bin/pint`.

### `booking_hours` JSON shape (defined here, used throughout)

`BotSetting.booking_hours` is a map of lowercase weekday → array of open ranges, times `HH:MM` in `booking_timezone`:

```json
{ "monday": [{ "start": "09:00", "end": "17:00" }], "tuesday": [{ "start": "09:00", "end": "17:00" }] }
```

A missing/empty weekday means closed that day. `null` means closed every day.

### `ClaudeClient` normalized response shape (defined here, used throughout)

`createMessage(array $params): array` returns:

```php
[
  'stop_reason' => 'tool_use' | 'end_turn' | 'max_tokens' | 'refusal',
  'content' => [
    ['type' => 'text', 'text' => '...'],
    ['type' => 'tool_use', 'id' => 'toolu_...', 'name' => 'book_appointment', 'input' => [...]],
  ],
]
```

---

## Task 1: Add Anthropic SDK and config

**Files:**
- Modify: `composer.json`
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Require the SDK**

Run: `composer require anthropic-ai/sdk`
Expected: package added to `composer.json` `require`, `composer.lock` updated.

- [ ] **Step 2: Add config block**

In `config/services.php`, add inside the returned array:

```php
'anthropic' => [
    'key' => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
],
```

- [ ] **Step 3: Document the env var**

In `.env.example`, add under the existing keys:

```
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-opus-4-8
```

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/services.php .env.example
git commit -m "chore: add anthropic sdk and config"
```

---

## Task 2: Database schema and models

**Files:**
- Create: `database/migrations/2026_06_09_000001_create_conversations_table.php`
- Create: `database/migrations/2026_06_09_000002_create_messages_table.php`
- Create: `database/migrations/2026_06_09_000003_create_appointments_table.php`
- Create: `app/Models/Conversation.php`, `app/Models/Message.php`, `app/Models/Appointment.php`
- Create: `database/factories/ConversationFactory.php`, `MessageFactory.php`, `AppointmentFactory.php`
- Modify: `app/Models/Workspace.php`
- Test: `tests/Feature/ConversationModelTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ConversationModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Actions\Workspaces\CreateWorkspaceForUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_has_messages_and_appointments(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester-1',
            'status' => 'active',
        ]);

        $conversation->messages()->create(['role' => 'user', 'content' => 'hi']);
        $conversation->appointments()->create([
            'workspace_id' => $workspace->id,
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'booked',
        ]);

        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(1, $conversation->appointments()->count());
        $this->assertSame('web', $conversation->channel);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter test_conversation_has_messages_and_appointments`
Expected: FAIL — class `App\Models\Conversation` not found.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_06_09_000001_create_conversations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('external_participant_id');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['workspace_id', 'channel', 'external_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
```

`database/migrations/2026_06_09_000002_create_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
```

`database/migrations/2026_06_09_000003_create_appointments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_contact');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('booked');
            $table->string('external_event_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/Conversation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'channel', 'external_participant_id', 'status'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
```

`app/Models/Message.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = ['conversation_id', 'role', 'content', 'tool_calls'];

    protected $casts = ['tool_calls' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
```

`app/Models/Appointment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'conversation_id', 'customer_name', 'customer_contact',
        'starts_at', 'ends_at', 'status', 'external_event_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
```

- [ ] **Step 5: Add Workspace relations**

In `app/Models/Workspace.php`, add `use Illuminate\Database\Eloquent\Relations\HasMany;` to the imports and these methods inside the class:

```php
public function conversations(): HasMany
{
    return $this->hasMany(Conversation::class);
}

public function appointments(): HasMany
{
    return $this->hasMany(Appointment::class);
}
```

- [ ] **Step 6: Write the factories**

`database/factories/ConversationFactory.php`:

```php
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
```

`database/factories/MessageFactory.php`:

```php
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
```

`database/factories/AppointmentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_name' => fake()->name(),
            'customer_contact' => fake()->safeEmail(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'booked',
            'external_event_id' => null,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter test_conversation_has_messages_and_appointments`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models database/factories
git commit -m "feat: add conversation, message, appointment models"
```

---

## Task 3: BookingService interface and FakeBookingService availability

**Files:**
- Create: `app/Services/Booking/BookingService.php`
- Create: `app/Services/Booking/FakeBookingService.php`
- Test: `tests/Unit/FakeBookingServiceTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FakeBookingServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Booking\FakeBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeBookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceWithHours(): \App\Models\Workspace
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'booking_timezone' => 'UTC',
            'default_appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
            'booking_hours' => ['monday' => [['start' => '09:00', 'end' => '11:00']]],
        ]);

        return $workspace->fresh();
    }

    public function test_availability_returns_slots_within_hours(): void
    {
        $workspace = $this->workspaceWithHours();
        // 2026-06-15 is a Monday.
        $range = Carbon::parse('2026-06-15', 'UTC');

        $slots = app(FakeBookingService::class)->availability($workspace, $range);

        // 09:00, 09:30, 10:00, 10:30 -> 4 slots (each 30m within 09:00-11:00).
        $this->assertCount(4, $slots);
        $this->assertSame('2026-06-15T09:00:00+00:00', $slots[0]);
    }

    public function test_booked_slot_is_excluded(): void
    {
        $workspace = $this->workspaceWithHours();
        $workspace->appointments()->create([
            'workspace_id' => $workspace->id,
            'customer_name' => 'Taken',
            'customer_contact' => 'taken@example.com',
            'starts_at' => Carbon::parse('2026-06-15T09:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-15T09:30:00', 'UTC'),
            'status' => 'booked',
        ]);
        $range = Carbon::parse('2026-06-15', 'UTC');

        $slots = app(FakeBookingService::class)->availability($workspace, $range);

        $this->assertNotContains('2026-06-15T09:00:00+00:00', $slots);
        $this->assertCount(3, $slots);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter FakeBookingServiceTest`
Expected: FAIL — `App\Services\Booking\FakeBookingService` not found.

- [ ] **Step 3: Write the interface**

`app/Services/Booking/BookingService.php`:

```php
<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

interface BookingService
{
    /**
     * Open slot start times as ISO-8601 strings, for the given day.
     *
     * @return array<int, string>
     */
    public function availability(Workspace $workspace, Carbon $day): array;

    /** @param array{name: string, contact: string} $customer */
    public function book(Workspace $workspace, Carbon $startsAt, array $customer, ?int $conversationId = null): Appointment;

    /** @return Collection<int, Appointment> */
    public function find(Workspace $workspace, string $customerContact): Collection;

    public function reschedule(Appointment $appointment, Carbon $newStartsAt): Appointment;

    public function cancel(Appointment $appointment): void;
}
```

- [ ] **Step 4: Write FakeBookingService**

`app/Services/Booking/FakeBookingService.php`:

```php
<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FakeBookingService implements BookingService
{
    public function availability(Workspace $workspace, Carbon $day): array
    {
        $settings = $workspace->botSetting;
        $tz = $settings->booking_timezone ?: 'UTC';
        $duration = (int) $settings->default_appointment_duration_minutes;
        $buffer = (int) $settings->buffer_minutes;
        $hours = $settings->booking_hours ?? [];

        $day = $day->copy()->setTimezone($tz)->startOfDay();
        $weekday = strtolower($day->format('l'));
        $ranges = $hours[$weekday] ?? [];

        $booked = $workspace->appointments()
            ->where('status', 'booked')
            ->whereDate('starts_at', $day->toDateString())
            ->get();

        $slots = [];
        foreach ($ranges as $range) {
            $cursor = $day->copy()->setTimeFromTimeString($range['start']);
            $end = $day->copy()->setTimeFromTimeString($range['end']);

            while ($cursor->copy()->addMinutes($duration)->lte($end)) {
                $slotStart = $cursor->copy();
                $slotEnd = $cursor->copy()->addMinutes($duration);

                $conflict = $booked->first(fn (Appointment $a) =>
                    $slotStart->lt($a->ends_at) && $slotEnd->gt($a->starts_at));

                if (! $conflict) {
                    $slots[] = $slotStart->toIso8601String();
                }

                $cursor->addMinutes($duration + $buffer);
            }
        }

        return $slots;
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

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter FakeBookingServiceTest`
Expected: PASS (both tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Booking tests/Unit/FakeBookingServiceTest.php
git commit -m "feat: add booking service interface and fake calendar"
```

---

## Task 4: ClaudeClient interface, fake double, and real adapter

**Files:**
- Create: `app/Services/Bot/Claude/ClaudeClient.php`
- Create: `app/Services/Bot/Claude/AnthropicClaudeClient.php`
- Create: `tests/Support/FakeClaudeClient.php`
- Test: `tests/Unit/FakeClaudeClientTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/FakeClaudeClientTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class FakeClaudeClientTest extends TestCase
{
    public function test_returns_scripted_responses_in_order(): void
    {
        $client = new FakeClaudeClient([
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'check_availability', 'input' => []],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Booked!'],
            ]],
        ]);

        $first = $client->createMessage([]);
        $second = $client->createMessage([]);

        $this->assertSame('tool_use', $first['stop_reason']);
        $this->assertSame('Booked!', $second['content'][0]['text']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter FakeClaudeClientTest`
Expected: FAIL — `Tests\Support\FakeClaudeClient` not found.

- [ ] **Step 3: Write the interface**

`app/Services/Bot/Claude/ClaudeClient.php`:

```php
<?php

namespace App\Services\Bot\Claude;

interface ClaudeClient
{
    /**
     * Send one Messages API request and return a normalized response.
     *
     * @param array<string, mixed> $params model, max_tokens, system, messages, tools, thinking, output_config
     * @return array{stop_reason: string, content: array<int, array<string, mixed>>}
     */
    public function createMessage(array $params): array;
}
```

- [ ] **Step 4: Write the fake double**

`tests/Support/FakeClaudeClient.php`:

```php
<?php

namespace Tests\Support;

use App\Services\Bot\Claude\ClaudeClient;

class FakeClaudeClient implements ClaudeClient
{
    /** @var array<int, array<string, mixed>> */
    private array $responses;

    private int $index = 0;

    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @param array<int, array<string, mixed>> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function createMessage(array $params): array
    {
        $this->requests[] = $params;

        $response = $this->responses[$this->index] ?? [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '']],
        ];
        $this->index++;

        return $response;
    }
}
```

- [ ] **Step 5: Write the real adapter**

> The exact PHP SDK call shape for tool use must be confirmed against the SDK before writing this file. Before implementing, WebFetch `https://github.com/anthropics/anthropic-sdk-php` (per the claude-api skill `shared/live-sources.md`) and confirm the `messages` create method, tool parameter format, and beta requirements for `claude-opus-4-8` (adaptive thinking, no `budget_tokens`/sampling params). Map the SDK's response object into the normalized array shape defined in the File Structure section.

`app/Services/Bot/Claude/AnthropicClaudeClient.php`:

```php
<?php

namespace App\Services\Bot\Claude;

use Anthropic\Anthropic;

class AnthropicClaudeClient implements ClaudeClient
{
    public function __construct(private Anthropic $anthropic)
    {
    }

    public function createMessage(array $params): array
    {
        // Confirm method name + param/response shapes against the PHP SDK repo
        // before finalizing. Translate the SDK response into:
        //   ['stop_reason' => string, 'content' => [ ['type'=>..., ...], ... ]]
        $response = $this->anthropic->messages()->create($params);

        return [
            'stop_reason' => $response->stopReason,
            'content' => array_map(
                fn ($block) => (array) $block,
                $response->content,
            ),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter FakeClaudeClientTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Bot/Claude tests/Support/FakeClaudeClient.php tests/Unit/FakeClaudeClientTest.php
git commit -m "feat: add claude client interface, fake, and adapter"
```

---

## Task 5: SystemPromptBuilder

**Files:**
- Create: `app/Services/Bot/SystemPromptBuilder.php`
- Test: `tests/Unit/SystemPromptBuilderTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/SystemPromptBuilderTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Bot\SystemPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_includes_business_details(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update([
            'business_name' => 'North Star Studio',
            'business_description' => 'We book consultations.',
            'bot_tone' => 'Warm and concise.',
            'cancellation_instructions' => 'Reply to reschedule.',
        ]);

        $prompt = app(SystemPromptBuilder::class)->build($workspace->fresh()->botSetting);

        $this->assertStringContainsString('North Star Studio', $prompt);
        $this->assertStringContainsString('We book consultations.', $prompt);
        $this->assertStringContainsString('Warm and concise.', $prompt);
        $this->assertStringContainsString('Reply to reschedule.', $prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SystemPromptBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the builder**

`app/Services/Bot/SystemPromptBuilder.php`:

```php
<?php

namespace App\Services\Bot;

use App\Models\BotSetting;
use Carbon\Carbon;

class SystemPromptBuilder
{
    public function build(BotSetting $settings): string
    {
        $tz = $settings->booking_timezone ?: 'UTC';
        $now = Carbon::now($tz)->toDayDateTimeString();
        $tone = $settings->bot_tone ?: 'Friendly, concise, and professional.';
        $cancellation = $settings->cancellation_instructions ?: 'Ask the customer to contact the business to cancel.';

        return <<<PROMPT
        You are the appointment-booking assistant for "{$settings->business_name}".

        About the business:
        {$settings->business_description}

        Tone: {$tone}

        The current date and time in the business timezone ({$tz}) is {$now}.
        Appointments are {$settings->default_appointment_duration_minutes} minutes long.

        Your job:
        - Answer questions about the business using the information above.
        - Book, reschedule, or cancel appointments using the provided tools. Never invent
          availability — always call check_availability before offering times.
        - Collect the customer's name and contact before booking.
        - Cancellation guidance: {$cancellation}
        - If the request is something you cannot handle, call request_human_handoff with a
          short reason rather than guessing.

        Keep replies short and conversational.
        PROMPT;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter SystemPromptBuilderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bot/SystemPromptBuilder.php tests/Unit/SystemPromptBuilderTest.php
git commit -m "feat: add system prompt builder"
```

---

## Task 6: ToolContext and ToolRegistry

**Files:**
- Create: `app/Services/Bot/Tools/ToolContext.php`
- Create: `app/Services/Bot/Tools/ToolRegistry.php`
- Test: `tests/Feature/ToolRegistryTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ToolRegistryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Bot\Tools\ToolContext;
use App\Services\Bot\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function context(): ToolContext
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
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

    public function test_book_appointment_creates_record(): void
    {
        $context = $this->context();

        $result = app(ToolRegistry::class)->execute('book_appointment', [
            'starts_at' => '2026-06-15T09:00:00+00:00',
            'customer_name' => 'Sam',
            'customer_contact' => 'sam@example.com',
        ], $context);

        $this->assertDatabaseHas('appointments', [
            'customer_contact' => 'sam@example.com',
            'status' => 'booked',
        ]);
        $this->assertStringContainsString('booked', strtolower($result));
    }

    public function test_handoff_marks_conversation(): void
    {
        $context = $this->context();

        app(ToolRegistry::class)->execute('request_human_handoff', ['reason' => 'angry customer'], $context);

        $this->assertSame('needs_human', $context->conversation->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ToolRegistryTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write ToolContext**

`app/Services/Bot/Tools/ToolContext.php`:

```php
<?php

namespace App\Services\Bot\Tools;

use App\Models\Conversation;
use App\Models\Workspace;

class ToolContext
{
    public function __construct(
        public Workspace $workspace,
        public Conversation $conversation,
    ) {
    }
}
```

- [ ] **Step 4: Write ToolRegistry**

`app/Services/Bot/Tools/ToolRegistry.php`:

```php
<?php

namespace App\Services\Bot\Tools;

use App\Services\Booking\BookingService;
use Carbon\Carbon;

class ToolRegistry
{
    public function __construct(private BookingService $booking)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            [
                'name' => 'check_availability',
                'description' => 'List open appointment slots for a given date. Call this whenever the customer asks about times or before offering any slot.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Date to check, YYYY-MM-DD.'],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'book_appointment',
                'description' => 'Book an appointment at a specific start time. Collect the customer name and contact first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'starts_at' => ['type' => 'string', 'description' => 'ISO-8601 start time, e.g. 2026-06-15T09:00:00+00:00.'],
                        'customer_name' => ['type' => 'string'],
                        'customer_contact' => ['type' => 'string', 'description' => 'Email or phone.'],
                    ],
                    'required' => ['starts_at', 'customer_name', 'customer_contact'],
                ],
            ],
            [
                'name' => 'find_booking',
                'description' => "Find a customer's existing booked appointments by their contact (email or phone).",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_contact' => ['type' => 'string'],
                    ],
                    'required' => ['customer_contact'],
                ],
            ],
            [
                'name' => 'reschedule_appointment',
                'description' => 'Move an existing appointment to a new start time. Use find_booking first to get the appointment_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'appointment_id' => ['type' => 'integer'],
                        'new_starts_at' => ['type' => 'string', 'description' => 'ISO-8601 start time.'],
                    ],
                    'required' => ['appointment_id', 'new_starts_at'],
                ],
            ],
            [
                'name' => 'cancel_appointment',
                'description' => 'Cancel an existing appointment. Use find_booking first to get the appointment_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'appointment_id' => ['type' => 'integer'],
                    ],
                    'required' => ['appointment_id'],
                ],
            ],
            [
                'name' => 'request_human_handoff',
                'description' => 'Flag this conversation for a human when you cannot help. Provide a short reason.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['reason'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function execute(string $name, array $input, ToolContext $context): string
    {
        return match ($name) {
            'check_availability' => $this->checkAvailability($input, $context),
            'book_appointment' => $this->bookAppointment($input, $context),
            'find_booking' => $this->findBooking($input, $context),
            'reschedule_appointment' => $this->rescheduleAppointment($input, $context),
            'cancel_appointment' => $this->cancelAppointment($input, $context),
            'request_human_handoff' => $this->requestHumanHandoff($input, $context),
            default => "Unknown tool: {$name}",
        };
    }

    private function checkAvailability(array $input, ToolContext $context): string
    {
        $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
        $day = Carbon::parse($input['date'], $tz);
        $slots = $this->booking->availability($context->workspace, $day);

        return empty($slots)
            ? 'No open slots on that date.'
            : 'Open slots: '.implode(', ', $slots);
    }

    private function bookAppointment(array $input, ToolContext $context): string
    {
        $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
        $appointment = $this->booking->book(
            $context->workspace,
            Carbon::parse($input['starts_at'], $tz),
            ['name' => $input['customer_name'], 'contact' => $input['customer_contact']],
            $context->conversation->id,
        );

        return "Appointment booked (id {$appointment->id}) for {$appointment->starts_at->toIso8601String()}.";
    }

    private function findBooking(array $input, ToolContext $context): string
    {
        $found = $this->booking->find($context->workspace, $input['customer_contact']);

        if ($found->isEmpty()) {
            return 'No booked appointments found for that contact.';
        }

        return $found->map(fn ($a) => "id {$a->id}: {$a->starts_at->toIso8601String()}")->implode('; ');
    }

    private function rescheduleAppointment(array $input, ToolContext $context): string
    {
        $appointment = $context->workspace->appointments()->find($input['appointment_id']);
        if (! $appointment) {
            return 'Appointment not found.';
        }

        $tz = $context->workspace->botSetting->booking_timezone ?: 'UTC';
        $updated = $this->booking->reschedule($appointment, Carbon::parse($input['new_starts_at'], $tz));

        return "Rescheduled to {$updated->starts_at->toIso8601String()}.";
    }

    private function cancelAppointment(array $input, ToolContext $context): string
    {
        $appointment = $context->workspace->appointments()->find($input['appointment_id']);
        if (! $appointment) {
            return 'Appointment not found.';
        }

        $this->booking->cancel($appointment);

        return 'Appointment cancelled.';
    }

    private function requestHumanHandoff(array $input, ToolContext $context): string
    {
        $context->conversation->update(['status' => 'needs_human']);

        return 'A human has been notified and will follow up.';
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter ToolRegistryTest`
Expected: PASS (all three tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Bot/Tools tests/Feature/ToolRegistryTest.php
git commit -m "feat: add tool registry and tool context"
```

---

## Task 7: Bind interfaces in a service provider

**Files:**
- Create: `app/Providers/BotServiceProvider.php`
- Modify: `bootstrap/providers.php`

- [ ] **Step 1: Write the provider**

`app/Providers/BotServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Anthropic\Anthropic;
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
            $anthropic = Anthropic::factory()
                ->withApiKey((string) config('services.anthropic.key'))
                ->make();

            return new AnthropicClaudeClient($anthropic);
        });
    }
}
```

> Confirm the `Anthropic::factory()->withApiKey()->make()` construction against the PHP SDK repo when implementing Task 4's adapter; adjust this binding to match the SDK's actual client constructor.

- [ ] **Step 2: Register the provider**

In `bootstrap/providers.php`, add `App\Providers\BotServiceProvider::class,` to the returned array.

- [ ] **Step 3: Verify the app still boots**

Run: `php artisan config:clear && php artisan test --filter ToolRegistryTest`
Expected: PASS (binding resolves `BookingService`).

- [ ] **Step 4: Commit**

```bash
git add app/Providers/BotServiceProvider.php bootstrap/providers.php
git commit -m "feat: bind bot service interfaces"
```

---

## Task 8: BotEngine tool-use loop

**Files:**
- Create: `app/Services/Bot/BotEngine.php`
- Test: `tests/Feature/BotEngineTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/BotEngineTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Bot\BotEngine;
use App\Services\Bot\Claude\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class BotEngineTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): Conversation
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);

        return Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);
    }

    public function test_engine_runs_tool_then_returns_text(): void
    {
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'book_appointment', 'input' => [
                    'starts_at' => '2026-06-15T09:00:00+00:00',
                    'customer_name' => 'Sam',
                    'customer_contact' => 'sam@example.com',
                ]],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => "You're booked for Monday at 9am."],
            ]],
        ]));

        $conversation = $this->conversation();
        $reply = app(BotEngine::class)->handle($conversation, 'Book me Monday 9am, I am Sam, sam@example.com');

        $this->assertSame("You're booked for Monday at 9am.", $reply);
        $this->assertDatabaseHas('appointments', ['customer_contact' => 'sam@example.com']);
        // user + assistant(tool_use) + tool result + assistant(text) all persisted.
        $this->assertGreaterThanOrEqual(3, $conversation->messages()->count());
    }

    public function test_disabled_bot_does_not_reply(): void
    {
        $conversation = $this->conversation();
        $conversation->workspace->botSetting->update(['is_enabled' => false]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([]));

        $reply = app(BotEngine::class)->handle($conversation->fresh(), 'hello?');

        $this->assertSame('', $reply);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BotEngineTest`
Expected: FAIL — `App\Services\Bot\BotEngine` not found.

- [ ] **Step 3: Write BotEngine**

`app/Services/Bot/BotEngine.php`:

```php
<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Services\Bot\Claude\ClaudeClient;
use App\Services\Bot\Tools\ToolContext;
use App\Services\Bot\Tools\ToolRegistry;
use Throwable;

class BotEngine
{
    private const MAX_ITERATIONS = 8;

    public function __construct(
        private ClaudeClient $claude,
        private ToolRegistry $tools,
        private SystemPromptBuilder $prompts,
    ) {
    }

    public function handle(Conversation $conversation, string $userText): string
    {
        $workspace = $conversation->workspace;
        $settings = $workspace->botSetting;

        if (! $settings->is_enabled) {
            return '';
        }

        $conversation->messages()->create(['role' => 'user', 'content' => $userText]);

        $system = $this->prompts->build($settings);
        $context = new ToolContext($workspace, $conversation);

        // Build Claude message history from persisted text turns.
        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->whereNotNull('content')
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        try {
            for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
                $response = $this->claude->createMessage([
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => 1024,
                    'system' => $system,
                    'messages' => $messages,
                    'tools' => $this->tools->definitions(),
                    'thinking' => ['type' => 'adaptive'],
                    'output_config' => ['effort' => 'high'],
                ]);

                $messages[] = ['role' => 'assistant', 'content' => $response['content']];

                if (($response['stop_reason'] ?? null) !== 'tool_use') {
                    $text = $this->extractText($response['content']);
                    $conversation->messages()->create(['role' => 'assistant', 'content' => $text]);

                    return $text;
                }

                // Execute every tool_use block, collect tool_result blocks.
                $toolResults = [];
                foreach ($response['content'] as $block) {
                    if (($block['type'] ?? null) !== 'tool_use') {
                        continue;
                    }

                    $output = $this->tools->execute($block['name'], $block['input'] ?? [], $context);

                    $conversation->messages()->create([
                        'role' => 'tool',
                        'content' => $output,
                        'tool_calls' => ['name' => $block['name'], 'input' => $block['input'] ?? []],
                    ]);

                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => $output,
                    ];
                }

                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }

            // Loop guard tripped — hand off.
            $conversation->update(['status' => 'needs_human']);

            return 'Let me get a teammate to help with that.';
        } catch (Throwable $e) {
            report($e);
            $conversation->update(['status' => 'needs_human']);

            return "Sorry, I'm having trouble right now — someone will follow up shortly.";
        }
    }

    /** @param array<int, array<string, mixed>> $content */
    private function extractText(array $content): string
    {
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        return trim($text);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter BotEngineTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Bot/BotEngine.php tests/Feature/BotEngineTest.php
git commit -m "feat: add bot engine tool-use loop"
```

---

## Task 9: ConversationChannel and WebChatChannel

**Files:**
- Create: `app/Services/Channels/ConversationChannel.php`
- Create: `app/Services/Channels/WebChatChannel.php`
- Test: `tests/Feature/WebChatChannelTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/WebChatChannelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Channels\WebChatChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebChatChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_reply_persists_assistant_message(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);

        app(WebChatChannel::class)->sendReply($conversation, 'Hello there.');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello there.',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter WebChatChannelTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the interface**

`app/Services/Channels/ConversationChannel.php`:

```php
<?php

namespace App\Services\Channels;

use App\Models\Conversation;

interface ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): void;
}
```

- [ ] **Step 4: Write WebChatChannel**

> Note: `BotEngine` already persists the final assistant message. `WebChatChannel::sendReply` is the channel-agnostic seam the future `FacebookChannel` will replace (it will instead POST to the Messenger Send API). For the web tester it is a no-op-safe persistence guard: if the reply text was already persisted by the engine this turn, skip; otherwise persist. To keep the seam honest and the test meaningful, persist here and have the controller (Task 11) rely on this channel rather than the engine return value.

`app/Services/Channels/WebChatChannel.php`:

```php
<?php

namespace App\Services\Channels;

use App\Models\Conversation;

class WebChatChannel implements ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): void
    {
        if ($text === '') {
            return;
        }

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $text,
        ]);
    }
}
```

> Because `BotEngine` also persists the final assistant text, to avoid a duplicate assistant row, remove the `extractText` persistence line from `BotEngine` (the `$conversation->messages()->create(['role' => 'assistant', 'content' => $text]);` call inside the non-tool branch) and let the channel own outbound persistence. Update `BotEngineTest`'s count assertion to `assertGreaterThanOrEqual(2, ...)` after this change. Make this edit in this task and re-run `php artisan test --filter BotEngineTest` to confirm still green.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter WebChatChannelTest && php artisan test --filter BotEngineTest`
Expected: PASS for both.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Channels app/Services/Bot/BotEngine.php tests/Feature/WebChatChannelTest.php tests/Feature/BotEngineTest.php
git commit -m "feat: add conversation channel seam and web chat channel"
```

---

## Task 10: ProcessIncomingMessage job

**Files:**
- Create: `app/Jobs/ProcessIncomingMessage.php`
- Test: `tests/Feature/ProcessIncomingMessageTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ProcessIncomingMessageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Bot\Claude\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class ProcessIncomingMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_conversation_and_persists_reply(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Hi! How can I help?'],
            ]],
        ]));

        (new ProcessIncomingMessage($workspace->id, 'web', 'visitor-1', 'hello'))
            ->handle(app(\App\Services\Bot\BotEngine::class), app(\App\Services\Channels\WebChatChannel::class));

        $conversation = Conversation::where('external_participant_id', 'visitor-1')->firstOrFail();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hi! How can I help?',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ProcessIncomingMessageTest`
Expected: FAIL — `App\Jobs\ProcessIncomingMessage` not found.

- [ ] **Step 3: Write the job**

`app/Jobs/ProcessIncomingMessage.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\Bot\BotEngine;
use App\Services\Channels\ConversationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $workspaceId,
        public string $channel,
        public string $participantId,
        public string $text,
    ) {
    }

    public function handle(BotEngine $engine, ConversationChannel $channel): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'channel' => $this->channel,
                'external_participant_id' => $this->participantId,
            ],
            ['status' => 'active'],
        );

        $reply = $engine->handle($conversation, $this->text);

        $channel->sendReply($conversation, $reply);
    }
}
```

- [ ] **Step 4: Bind the channel**

In `app/Providers/BotServiceProvider.php`, add to `register()`:

```php
$this->app->bind(
    \App\Services\Channels\ConversationChannel::class,
    \App\Services\Channels\WebChatChannel::class,
);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter ProcessIncomingMessageTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessIncomingMessage.php app/Providers/BotServiceProvider.php tests/Feature/ProcessIncomingMessageTest.php
git commit -m "feat: add process incoming message job"
```

---

## Task 11: Web-chat tester page

**Files:**
- Create: `app/Http/Controllers/ChatTesterController.php`
- Create: `resources/views/chat-tester/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ChatTesterTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ChatTesterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Services\Bot\Claude\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class ChatTesterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_a_message_and_see_reply(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Hello from the bot.'],
            ]],
        ]));

        $this->actingAs($user)
            ->post('/chat-tester', ['message' => 'hi'])
            ->assertRedirect('/chat-tester');

        $this->actingAs($user)
            ->get('/chat-tester')
            ->assertOk()
            ->assertSee('Hello from the bot.');
    }

    public function test_page_requires_auth(): void
    {
        $this->get('/chat-tester')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ChatTesterTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/ChatTesterController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatTesterController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $request->user()->workspaces()->with('botSetting')->firstOrFail();

        $conversation = $workspace->conversations()
            ->where('channel', 'web')
            ->where('external_participant_id', $this->participantId($request))
            ->with('messages')
            ->first();

        return view('chat-tester.index', [
            'workspace' => $workspace,
            'settings' => $workspace->botSetting,
            'messages' => $conversation
                ? $conversation->messages()->whereIn('role', ['user', 'assistant'])->orderBy('id')->get()
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $workspace = $request->user()->workspaces()->firstOrFail();

        // Dispatch synchronously so the reply is ready on redirect.
        ProcessIncomingMessage::dispatchSync(
            $workspace->id,
            'web',
            $this->participantId($request),
            $validated['message'],
        );

        return redirect()->route('chat-tester.index');
    }

    private function participantId(Request $request): string
    {
        return 'tester-'.$request->user()->id;
    }
}
```

- [ ] **Step 4: Write the view**

`resources/views/chat-tester/index.blade.php`:

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Chat Tester</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @unless($settings->is_enabled)
                    <p class="mb-4 text-sm text-red-600">Bot is disabled. Enable it in Bot Settings to chat.</p>
                @endunless

                <div class="space-y-3 mb-6">
                    @forelse($messages as $message)
                        <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="rounded-lg px-3 py-2 text-sm {{ $message->role === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800' }}">
                                {{ $message->content }}
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Send a message to start testing your bot.</p>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('chat-tester.store') }}" class="flex gap-2">
                    @csrf
                    <x-text-input name="message" class="flex-1" placeholder="Type a message…" required autofocus />
                    <x-primary-button>Send</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('message')" class="mt-2" />
            </div>
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add `use App\Http\Controllers\ChatTesterController;` to the imports and these routes inside the existing `Route::middleware(['auth', 'verified'])->group(function () { ... });` block:

```php
Route::get('/chat-tester', [ChatTesterController::class, 'index'])->name('chat-tester.index');
Route::post('/chat-tester', [ChatTesterController::class, 'store'])->name('chat-tester.store');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter ChatTesterTest`
Expected: PASS (both tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ChatTesterController.php resources/views/chat-tester routes/web.php tests/Feature/ChatTesterTest.php
git commit -m "feat: add local web chat tester"
```

---

## Task 12: Full-suite green and format

**Files:** none (verification only)

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: all tests pass, including the pre-existing auth/workspace/dashboard tests.

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

- **Spec coverage:** abilities (FAQ/book/reschedule/cancel/handoff) → Task 6 tools + Task 5 prompt; channel-agnostic engine → Task 8; queued processing → Task 10; fake calendar behind interface → Tasks 3 + 7; web-chat tester → Task 11; fake Anthropic client for tests → Task 4; error handling + disabled bot → Task 8; `is_enabled`/trial gating → Task 8 (`is_enabled`; hard trial enforcement deferred per spec non-goals). All spec sections map to a task.
- **Type consistency:** `ClaudeClient::createMessage(array): array` and the normalized `['stop_reason','content']` shape are used identically in the fake (Task 4), engine (Task 8), and all engine tests. `BookingService` signatures defined in Task 3 are called unchanged in Task 6. `ConversationChannel::sendReply` defined in Task 9 is used in Task 10.
- **Known follow-up baked into the plan:** Task 9 explicitly removes the duplicate assistant-persistence line introduced in Task 8 and updates the affected assertion, so outbound persistence lives only in the channel.
- **SDK caveat:** the only un-pinned detail is the PHP SDK's exact client/messages syntax (Task 4 + Task 7 binding). Both steps instruct WebFetching `anthropic-sdk-php` before finalizing, and all business logic is isolated from it behind `ClaudeClient`, so tests and every other task are unaffected.
