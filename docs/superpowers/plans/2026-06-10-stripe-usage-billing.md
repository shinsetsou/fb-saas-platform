# Stripe Usage-Based Billing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Stripe usage-based subscriptions (monthly base + included quota + per-reply overage) to the trial-first SaaS, billing every non-empty AI reply.

**Architecture:** Laravel Cashier with `Workspace` as the billable model. A local `usage_records` ledger is the source of truth for billable replies (one row per assistant `Message`, unique on `message_id`); Stripe meter events are derived from it and only pushed for subscribed workspaces. The bot gate becomes `is_enabled && (trial active || subscribed)`. A `UsageMeter` gateway (real `StripeUsageMeter` + `FakeUsageMeter`) mirrors the existing `FacebookGraph` pattern.

**Tech Stack:** Laravel 12, Laravel Cashier ^15 (Stripe), SQLite, PHPUnit, Tailwind/Blade.

**Spec:** `docs/superpowers/specs/2026-06-10-stripe-usage-billing-design.md`

**Conventions (read before starting):**
- TDD: write the failing test, watch it fail, minimal code to pass, commit.
- Feature tests use `RefreshDatabase`, hit real routes, bind fakes in the container. Mirror `tests/Feature/FacebookConnectionTest.php`.
- Run a single test file: `php artisan test tests/Feature/Foo.php`
- Run a filter: `php artisan test --filter test_name`
- Format before commit: `./vendor/bin/pint`
- Never call live Stripe in tests. Simulate a subscription by inserting a `subscriptions` row with `stripe_status = 'active'`.

---

## File Structure

**Create:**
- `database/migrations/2026_06_10_000004_create_usage_records_table.php` — local usage ledger
- `app/Models/UsageRecord.php` — ledger model
- `database/factories/UsageRecordFactory.php`
- `app/Services/Billing/UsageMeter.php` — gateway interface
- `app/Services/Billing/StripeUsageMeter.php` — real Cashier-backed meter reporter
- `app/Services/Billing/UsageRecorder.php` — records ledger row + reports when subscribed
- `app/Http/Controllers/BillingController.php` — checkout + portal
- `tests/Support/FakeUsageMeter.php`
- Test files under `tests/Feature/` (one per task below)

**Modify:**
- `composer.json` — add `laravel/cashier` (via composer require)
- Published Cashier migrations — retarget `users` → `workspaces`, `user_id` → `workspace_id`
- `app/Models/Workspace.php` — `Billable` trait, `stripeEmail()`, `stripeName()`, `canUseBot()`, `usageRecords()`
- `app/Providers/BotServiceProvider.php` — `Cashier::useCustomerModel(Workspace::class)`, bind `UsageMeter`
- `app/Services/Channels/ConversationChannel.php` — `sendReply` returns `?Message`
- `app/Services/Channels/WebChatChannel.php` — return the Message
- `app/Services/Facebook/FacebookMessengerChannel.php` — return the Message
- `app/Jobs/ProcessIncomingMessage.php` — record usage after reply
- `app/Http/Controllers/FacebookWebhookController.php` — gate uses `canUseBot()`
- `app/Http/Controllers/DashboardController.php` — billing status + replies count
- `resources/views/dashboard.blade.php` — billing block + `subscribed` flash
- `config/services.php` — `stripe` entry
- `.env.example` — `STRIPE_*` keys
- `routes/web.php` — billing routes
- `tests/Feature/WebChatChannelTest.php`, `tests/Feature/FacebookMessengerChannelTest.php` — assert returned Message
- `tests/Feature/DashboardTest.php` — billing assertions

---

## Task 1: Install Cashier with Workspace as the billable model

**Files:**
- Modify: `composer.json` (via `composer require`)
- Modify: published `database/migrations/*_add_cashier_columns_to_users_table.php` (retarget to `workspaces`)
- Modify: published `database/migrations/*_create_subscriptions_table.php` and `*_create_subscription_items_table.php` (`user_id` → `workspace_id`)
- Modify: `app/Models/Workspace.php`
- Modify: `app/Providers/BotServiceProvider.php`
- Test: `tests/Feature/WorkspaceBillableTest.php`

- [ ] **Step 1: Install Cashier**

Run:
```bash
composer require laravel/cashier
php artisan vendor:publish --tag="cashier-migrations"
```
Expected: package installs; three migrations appear under `database/migrations/` (cashier columns, subscriptions, subscription_items).

- [ ] **Step 2: Retarget the published migrations to Workspace**

In the published `*_add_cashier_columns_to_users_table.php`, change both `Schema::table('users', ...)` calls (up and down) to `Schema::table('workspaces', ...)`. Leave the columns as-is (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`).

In the published `*_create_subscriptions_table.php`, rename the owner column from `user_id` to `workspace_id` (it is referenced as `$table->foreignId('user_id')` or `$table->unsignedBigInteger('user_id')` plus an index — rename both the column and the index name). Do the same in `*_create_subscription_items_table.php` if it references `user_id` (it references `subscription_id`, so usually no change there — verify).

- [ ] **Step 3: Point Cashier at the Workspace model and add the Billable trait**

In `app/Providers/BotServiceProvider.php`, add to `register()` (imports at top):
```php
use App\Models\Workspace;
use Laravel\Cashier\Cashier;
```
and inside `register()`:
```php
Cashier::useCustomerModel(Workspace::class);
```

In `app/Models/Workspace.php`, add the trait and overrides:
```php
use Laravel\Cashier\Billable;

class Workspace extends Model
{
    use Billable, HasFactory;

    // ... existing code ...

    public function stripeEmail(): ?string
    {
        return $this->users()->wherePivot('role', 'owner')->first()?->email;
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }
}
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/WorkspaceBillableTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Billable;
use Tests\TestCase;

class WorkspaceBillableTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_is_billable(): void
    {
        $this->assertContains(Billable::class, class_uses_recursive(Workspace::class));
    }

    public function test_stripe_email_is_owner_email(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);

        $this->assertSame('owner@example.com', $workspace->stripeEmail());
        $this->assertSame($workspace->name, $workspace->stripeName());
    }

    public function test_workspaces_table_has_stripe_id_column(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->update(['stripe_id' => 'cus_test123']);

        $this->assertSame('cus_test123', $workspace->fresh()->stripe_id);
    }
}
```

- [ ] **Step 5: Run test to verify it fails, then passes after migration**

Run: `php artisan test tests/Feature/WorkspaceBillableTest.php`
Expected first run: FAIL (trait/columns missing) before Steps 2–3 are complete. After Steps 2–3 and with the in-memory DB running all migrations, expected: PASS.

Note: `stripe_id` must be added to `Workspace::$fillable` for the `update()` in the test — add `'stripe_id'` to the `$fillable` array (Cashier also accesses it directly, but the test uses mass assignment).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: install cashier with workspace as billable model"
```

---

## Task 2: Stripe config and env keys

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Test: none (config only; covered indirectly later)

- [ ] **Step 1: Add the stripe config entry**

In `config/services.php`, after the `facebook` entry, add:
```php
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price_base' => env('STRIPE_PRICE_BASE'),
        'price_metered' => env('STRIPE_PRICE_METERED'),
        'meter_event' => env('STRIPE_METER_EVENT', 'ai_replies'),
    ],
```
(Cashier reads `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET` from its own `config/cashier.php` defaults; duplicating the first three here is harmless and keeps all billing config in one place.)

- [ ] **Step 2: Add env keys**

In `.env.example`, after the `FACEBOOK_*` block, add:
```
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_PRICE_BASE=
STRIPE_PRICE_METERED=
STRIPE_METER_EVENT=ai_replies
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat: add stripe billing config and env keys"
```

---

## Task 3: usage_records ledger table and model

**Files:**
- Create: `database/migrations/2026_06_10_000004_create_usage_records_table.php`
- Create: `app/Models/UsageRecord.php`
- Create: `database/factories/UsageRecordFactory.php`
- Modify: `app/Models/Workspace.php` (add `usageRecords()`)
- Test: `tests/Feature/UsageRecordModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UsageRecordModelTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageRecordModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_id_is_unique(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => 'web',
            'external_participant_id' => 'p', 'status' => 'active',
        ]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'hi']);

        UsageRecord::create(['workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web']);

        $this->expectException(QueryException::class);
        UsageRecord::create(['workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web']);
    }

    public function test_workspace_has_many_usage_records(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => 'web',
            'external_participant_id' => 'p', 'status' => 'active',
        ]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'hi']);
        UsageRecord::create(['workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web']);

        $this->assertSame(1, $workspace->usageRecords()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/UsageRecordModelTest.php`
Expected: FAIL ("Class App\Models\UsageRecord does not exist" / no table).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_10_000004_create_usage_records_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/UsageRecord.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'message_id', 'channel', 'reported_at'];

    protected $casts = ['reported_at' => 'datetime'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
```

- [ ] **Step 5: Create the factory**

Create `database/factories/UsageRecordFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageRecord> */
class UsageRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel' => 'web',
            'reported_at' => null,
        ];
    }
}
```

- [ ] **Step 6: Add the relation to Workspace**

In `app/Models/Workspace.php`, add:
```php
public function usageRecords(): HasMany
{
    return $this->hasMany(UsageRecord::class);
}
```
(`HasMany` is already imported in Workspace.)

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/UsageRecordModelTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: add usage_records ledger table and model"
```

---

## Task 4: UsageMeter gateway (interface + real + fake)

**Files:**
- Create: `app/Services/Billing/UsageMeter.php`
- Create: `app/Services/Billing/StripeUsageMeter.php`
- Create: `tests/Support/FakeUsageMeter.php`
- Modify: `app/Providers/BotServiceProvider.php` (bind)
- Test: covered via Task 6 (UsageRecorder); no standalone test for the thin real wrapper

- [ ] **Step 1: Create the interface**

Create `app/Services/Billing/UsageMeter.php`:
```php
<?php

namespace App\Services\Billing;

use App\Models\Workspace;

interface UsageMeter
{
    public function report(Workspace $workspace, int $count = 1): void;
}
```

- [ ] **Step 2: Create the real implementation**

Create `app/Services/Billing/StripeUsageMeter.php`:
```php
<?php

namespace App\Services\Billing;

use App\Models\Workspace;

class StripeUsageMeter implements UsageMeter
{
    public function report(Workspace $workspace, int $count = 1): void
    {
        $workspace->reportMeterEvent(config('services.stripe.meter_event'), $count);
    }
}
```

- [ ] **Step 3: Create the fake**

Create `tests/Support/FakeUsageMeter.php`:
```php
<?php

namespace Tests\Support;

use App\Models\Workspace;
use App\Services\Billing\UsageMeter;

class FakeUsageMeter implements UsageMeter
{
    /** @var array<int, array{workspace_id: int, count: int}> */
    public array $reported = [];

    public function report(Workspace $workspace, int $count = 1): void
    {
        $this->reported[] = ['workspace_id' => $workspace->id, 'count' => $count];
    }
}
```

- [ ] **Step 4: Bind in BotServiceProvider**

In `app/Providers/BotServiceProvider.php` `register()`, add the binding (imports at top):
```php
use App\Services\Billing\StripeUsageMeter;
use App\Services\Billing\UsageMeter;
```
```php
$this->app->bind(UsageMeter::class, StripeUsageMeter::class);
```

- [ ] **Step 5: Verify nothing breaks**

Run: `php artisan test --filter WorkspaceBillableTest`
Expected: PASS (binding resolves; no behavior change yet).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: add usage meter gateway interface, stripe impl, fake"
```

---

## Task 5: ConversationChannel::sendReply returns the persisted Message

**Files:**
- Modify: `app/Services/Channels/ConversationChannel.php`
- Modify: `app/Services/Channels/WebChatChannel.php`
- Modify: `app/Services/Facebook/FacebookMessengerChannel.php`
- Modify: `tests/Feature/WebChatChannelTest.php`
- Modify: `tests/Feature/FacebookMessengerChannelTest.php`

- [ ] **Step 1: Update the WebChatChannel test (failing)**

In `tests/Feature/WebChatChannelTest.php`, replace the body of `test_send_reply_persists_assistant_message` to assert the return value, and add an empty-text case:
```php
    public function test_send_reply_returns_persisted_message(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);

        $message = app(WebChatChannel::class)->sendReply($conversation, 'Hello there.');

        $this->assertNotNull($message);
        $this->assertSame('assistant', $message->role);
        $this->assertSame('Hello there.', $message->content);
        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_send_reply_returns_null_on_empty_text(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'external_participant_id' => 'tester',
            'status' => 'active',
        ]);

        $this->assertNull(app(WebChatChannel::class)->sendReply($conversation, ''));
        $this->assertDatabaseCount('messages', 0);
    }
```
(Keep the existing `use` statements; remove the old single test method it replaces.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/WebChatChannelTest.php`
Expected: FAIL (sendReply returns void/null → `assertNotNull` fails or type error).

- [ ] **Step 3: Update the interface**

In `app/Services/Channels/ConversationChannel.php`:
```php
<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;

interface ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): ?Message;
}
```

- [ ] **Step 4: Update WebChatChannel**

In `app/Services/Channels/WebChatChannel.php`:
```php
<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;

class WebChatChannel implements ConversationChannel
{
    public function sendReply(Conversation $conversation, string $text): ?Message
    {
        if ($text === '') {
            return null;
        }

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $text,
        ]);
    }
}
```

- [ ] **Step 5: Update FacebookMessengerChannel**

In `app/Services/Facebook/FacebookMessengerChannel.php`, change `sendReply` to return the message:
```php
use App\Models\Message;
```
```php
    public function sendReply(Conversation $conversation, string $text): ?Message
    {
        if ($text === '') {
            return null;
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $text,
        ]);

        $page = $conversation->workspace->facebookPage;

        if ($page !== null) {
            $this->graph->sendMessage(
                $page->page_id,
                $page->page_access_token,
                $conversation->external_participant_id,
                $text,
            );
        }

        return $message;
    }
```

- [ ] **Step 6: Add the return assertion to the FacebookMessengerChannel test**

In `tests/Feature/FacebookMessengerChannelTest.php`, in `test_send_reply_persists_message_and_calls_graph`, capture and assert the return:
```php
        $message = app(FacebookMessengerChannel::class)->sendReply($conversation, 'Booked!');

        $this->assertNotNull($message);
        $this->assertSame('Booked!', $message->content);
```
(Keep the existing message + `$fake->sent` assertions.)

- [ ] **Step 7: Run both channel tests**

Run: `php artisan test tests/Feature/WebChatChannelTest.php tests/Feature/FacebookMessengerChannelTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: sendReply returns the persisted assistant message"
```

---

## Task 6: UsageRecorder service

**Files:**
- Create: `app/Services/Billing/UsageRecorder.php`
- Test: `tests/Feature/UsageRecorderTest.php`

Behavior: record a local `usage_records` row keyed on `message_id` (idempotent); only on a newly-created row AND when the workspace is subscribed, push to the `UsageMeter` and stamp `reported_at`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UsageRecorderTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\UsageMeter;
use App\Services\Billing\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeUsageMeter;
use Tests\TestCase;

class UsageRecorderTest extends TestCase
{
    use RefreshDatabase;

    private FakeUsageMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = new FakeUsageMeter;
        $this->app->instance(UsageMeter::class, $this->meter);
    }

    private function assistantMessage(Workspace $workspace, string $channel = 'web'): Message
    {
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel' => $channel,
            'external_participant_id' => 'p', 'status' => 'active',
        ]);

        return $conversation->messages()->create(['role' => 'assistant', 'content' => 'hi']);
    }

    private function subscribe(Workspace $workspace): void
    {
        $workspace->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.$workspace->id,
            'stripe_status' => 'active',
            'stripe_price' => 'price_base',
            'quantity' => 1,
        ]);
        $workspace->update(['stripe_id' => 'cus_'.$workspace->id]);
    }

    public function test_records_local_row_for_each_message(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $message = $this->assistantMessage($workspace);

        app(UsageRecorder::class)->record($message);

        $this->assertDatabaseHas('usage_records', [
            'workspace_id' => $workspace->id, 'message_id' => $message->id, 'channel' => 'web',
        ]);
    }

    public function test_is_idempotent_on_message_id(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $message = $this->assistantMessage($workspace);

        app(UsageRecorder::class)->record($message);
        app(UsageRecorder::class)->record($message);

        $this->assertDatabaseCount('usage_records', 1);
    }

    public function test_does_not_push_to_meter_during_trial(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $message = $this->assistantMessage($workspace);

        app(UsageRecorder::class)->record($message);

        $this->assertCount(0, $this->meter->reported);
        $this->assertNull($workspace->usageRecords()->first()->reported_at);
    }

    public function test_pushes_to_meter_and_stamps_when_subscribed(): void
    {
        $workspace = app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
        $this->subscribe($workspace);
        $message = $this->assistantMessage($workspace->fresh());

        app(UsageRecorder::class)->record($message);

        $this->assertCount(1, $this->meter->reported);
        $this->assertSame($workspace->id, $this->meter->reported[0]['workspace_id']);
        $this->assertNotNull($workspace->usageRecords()->first()->reported_at);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/UsageRecorderTest.php`
Expected: FAIL ("Class App\Services\Billing\UsageRecorder does not exist").

- [ ] **Step 3: Implement UsageRecorder**

Create `app/Services/Billing/UsageRecorder.php`:
```php
<?php

namespace App\Services\Billing;

use App\Models\Message;
use App\Models\UsageRecord;
use Throwable;

class UsageRecorder
{
    public function __construct(private UsageMeter $meter) {}

    public function record(Message $message): void
    {
        $conversation = $message->conversation;
        $workspace = $conversation->workspace;

        $record = UsageRecord::firstOrCreate(
            ['message_id' => $message->id],
            ['workspace_id' => $workspace->id, 'channel' => $conversation->channel],
        );

        if (! $record->wasRecentlyCreated) {
            return;
        }

        if (! $workspace->subscribed('default')) {
            return;
        }

        try {
            $this->meter->report($workspace);
            $record->update(['reported_at' => now()]);
        } catch (Throwable $e) {
            report($e); // leave reported_at null for replay
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/UsageRecorderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: add UsageRecorder service"
```

---

## Task 7: ProcessIncomingMessage records usage

**Files:**
- Modify: `app/Jobs/ProcessIncomingMessage.php`
- Test: `tests/Feature/ProcessIncomingMessageUsageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProcessIncomingMessageUsageTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Jobs\ProcessIncomingMessage;
use App\Models\User;
use App\Services\Bot\BotEngine;
use App\Services\Bot\Claude\ClaudeClient;
use App\Services\Channels\ChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeClient;
use Tests\TestCase;

class ProcessIncomingMessageUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_empty_reply_records_one_usage_row(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->botSetting->update(['is_enabled' => true]);

        $this->app->instance(ClaudeClient::class, new FakeClaudeClient([
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Hello!']]],
        ]));

        (new ProcessIncomingMessage($workspace->id, 'web', 'visitor-1', 'hi'))
            ->handle(app(BotEngine::class), app(ChannelResolver::class), app(\App\Services\Billing\UsageRecorder::class));

        $this->assertDatabaseCount('usage_records', 1);
    }

    public function test_disabled_bot_empty_reply_records_no_usage(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        // botSetting->is_enabled defaults to false → BotEngine returns '' → no reply

        (new ProcessIncomingMessage($workspace->id, 'web', 'visitor-1', 'hi'))
            ->handle(app(BotEngine::class), app(ChannelResolver::class), app(\App\Services\Billing\UsageRecorder::class));

        $this->assertDatabaseCount('usage_records', 0);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ProcessIncomingMessageUsageTest.php`
Expected: FAIL (handle signature has no third arg / usage not recorded).

- [ ] **Step 3: Update the job**

In `app/Jobs/ProcessIncomingMessage.php`, add the import and the recorder param, and record after the reply:
```php
use App\Services\Billing\UsageRecorder;
```
```php
    public function handle(BotEngine $engine, ChannelResolver $channels, UsageRecorder $recorder): void
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

        $message = $channels->for($conversation)->sendReply($conversation, $reply);

        if ($message !== null) {
            $recorder->record($message);
        }
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/ProcessIncomingMessageUsageTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the existing job test (regression)**

Run: `php artisan test tests/Feature/ProcessIncomingMessageTest.php`
Expected: FAIL — its `->handle(...)` call now needs the third argument. Update that call in `tests/Feature/ProcessIncomingMessageTest.php` to:
```php
        (new ProcessIncomingMessage($workspace->id, 'web', 'visitor-1', 'hello'))
            ->handle(app(BotEngine::class), app(ChannelResolver::class), app(\App\Services\Billing\UsageRecorder::class));
```
Then re-run: `php artisan test tests/Feature/ProcessIncomingMessageTest.php` → PASS.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: record usage for each non-empty bot reply"
```

---

## Task 8: canUseBot() gate

**Files:**
- Modify: `app/Models/Workspace.php`
- Modify: `app/Http/Controllers/FacebookWebhookController.php`
- Test: `tests/Feature/CanUseBotTest.php`, plus additions to `tests/Feature/FacebookWebhookTest.php`

- [ ] **Step 1: Write the failing unit test for canUseBot()**

Create `tests/Feature/CanUseBotTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanUseBotTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        return app(CreateWorkspaceForUser::class)->handle(User::factory()->create());
    }

    public function test_true_during_active_trial(): void
    {
        $workspace = $this->workspace();
        $this->assertTrue($workspace->canUseBot());
    }

    public function test_false_when_trial_expired_and_no_subscription(): void
    {
        $workspace = $this->workspace();
        $workspace->trial->update(['status' => 'expired', 'ends_at' => now()->subDay()]);

        $this->assertFalse($workspace->fresh()->canUseBot());
    }

    public function test_true_when_subscribed_even_if_trial_expired(): void
    {
        $workspace = $this->workspace();
        $workspace->trial->update(['status' => 'expired', 'ends_at' => now()->subDay()]);
        $workspace->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active',
            'stripe_price' => 'price_base', 'quantity' => 1,
        ]);

        $this->assertTrue($workspace->fresh()->canUseBot());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CanUseBotTest.php`
Expected: FAIL ("Call to undefined method ... canUseBot()").

- [ ] **Step 3: Implement canUseBot()**

In `app/Models/Workspace.php`:
```php
public function canUseBot(): bool
{
    return (bool) ($this->trial?->isActive() || $this->subscribed('default'));
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/CanUseBotTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Switch the webhook gate to canUseBot()**

In `app/Http/Controllers/FacebookWebhookController.php`, the eager load and gate change. Update the `FacebookPage::with(...)` to also load subscriptions, and change the gate condition:
```php
                $page = FacebookPage::with('workspace.botSetting', 'workspace.trial', 'workspace.subscriptions')
                    ->where('page_id', $pageId)
                    ->first();
```
```php
                if (! $workspace->botSetting?->is_enabled || ! $workspace->canUseBot()) {
                    continue;
                }
```

- [ ] **Step 6: Add a subscribed-workspace dispatch test to the webhook suite**

In `tests/Feature/FacebookWebhookTest.php`, add a test that a subscribed workspace with an expired trial still dispatches. Reuse the existing `connectedWorkspace`/`postWebhook`/`messagePayload` helpers:
```php
    public function test_subscribed_workspace_with_expired_trial_dispatches(): void
    {
        Queue::fake();
        $workspace = $this->connectedWorkspace(trialActive: false);
        $workspace->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_x', 'stripe_status' => 'active',
            'stripe_price' => 'price_base', 'quantity' => 1,
        ]);

        $this->postWebhook($this->messagePayload('PAGE_1', 'psid', 'mid-sub', 'hi'))
            ->assertOk();

        Queue::assertPushed(ProcessIncomingMessage::class);
    }
```

- [ ] **Step 7: Run the webhook suite**

Run: `php artisan test tests/Feature/FacebookWebhookTest.php`
Expected: PASS (existing `test_expired_trial_does_not_dispatch` still passes — trial expired AND no subscription → no dispatch; the new test passes — expired trial BUT subscribed → dispatches).

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: gate bot on canUseBot (trial active or subscribed)"
```

---

## Task 9: BillingController checkout + portal + routes

**Files:**
- Create: `app/Http/Controllers/BillingController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BillingRoutesTest.php`

Note: `checkout()` and `portal()` call Stripe and are not exercised against the live API in tests. The test verifies the routes exist and require auth. The Cashier webhook route (`/stripe/webhook`) is registered automatically by Cashier and is CSRF-exempt — no route or CSRF change needed for it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BillingRoutesTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_requires_auth(): void
    {
        $this->get('/billing/checkout')->assertRedirect('/login');
    }

    public function test_portal_requires_auth(): void
    {
        $this->get('/billing/portal')->assertRedirect('/login');
    }

    public function test_routes_are_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('billing.checkout'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('billing.portal'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/BillingRoutesTest.php`
Expected: FAIL (routes not registered → 404 instead of redirect; `Route::has` false).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/BillingController.php`:
```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Checkout;

class BillingController extends Controller
{
    public function checkout(Request $request): Checkout
    {
        $workspace = $request->user()->workspaces()->firstOrFail();

        return $workspace
            ->newSubscription('default', [config('services.stripe.price_base')])
            ->meteredPrice(config('services.stripe.price_metered'))
            ->checkout([
                'success_url' => route('dashboard').'?status=subscribed',
                'cancel_url' => route('dashboard'),
            ]);
    }

    public function portal(Request $request): RedirectResponse
    {
        $workspace = $request->user()->workspaces()->firstOrFail();

        return $workspace->redirectToBillingPortal(route('dashboard'));
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add the import:
```php
use App\Http\Controllers\BillingController;
```
and inside the existing `Route::middleware(['auth', 'verified'])->group(...)` block, add:
```php
    Route::get('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/BillingRoutesTest.php`
Expected: PASS (3 tests — unauthenticated requests redirect to `/login`; routes registered).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: add billing checkout and portal routes"
```

---

## Task 10: Dashboard billing status and view

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/views/dashboard.blade.php`
- Modify: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Write the failing test**

In `tests/Feature/DashboardTest.php`, add three tests (helpers/imports already present in the file):
```php
    public function test_dashboard_shows_trialing_billing_status(): void
    {
        $user = User::factory()->create(['name' => 'Morgan Owner']);
        app(CreateWorkspaceForUser::class)->handle($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Trialing')
            ->assertSee('Subscribe');
    }

    public function test_dashboard_shows_subscribed_status_and_manage_button(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active',
            'stripe_price' => 'price_base', 'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Subscribed')
            ->assertSee('Manage billing');
    }

    public function test_dashboard_shows_lapsed_when_trial_expired_and_unsubscribed(): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspaceForUser::class)->handle($user);
        $workspace->trial->update(['status' => 'expired', 'ends_at' => now()->subDay()]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Lapsed')
            ->assertSee('Subscribe');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/DashboardTest.php`
Expected: FAIL (no billing status rendered).

- [ ] **Step 3: Update DashboardController**

In `app/Http/Controllers/DashboardController.php`, eager-load subscriptions and compute the status + usage count. Replace the method body:
```php
    public function __invoke(Request $request): View
    {
        $workspace = $request->user()
            ->workspaces()
            ->with(['trial', 'botSetting', 'facebookPage', 'subscriptions'])
            ->firstOrFail();

        if ($workspace->subscribed('default')) {
            $billingStatus = 'subscribed';
        } elseif ($workspace->trial?->isActive()) {
            $billingStatus = 'trialing';
        } else {
            $billingStatus = 'lapsed';
        }

        $repliesThisPeriod = $workspace->usageRecords()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return view('dashboard', [
            'workspace' => $workspace,
            'trial' => $workspace->trial,
            'botSetting' => $workspace->botSetting,
            'facebookConnected' => $workspace->facebookPage !== null,
            'facebookPage' => $workspace->facebookPage,
            'googleConnected' => $workspace->googleAccount()->exists(),
            'billingStatus' => $billingStatus,
            'repliesThisPeriod' => $repliesThisPeriod,
        ]);
    }
```

- [ ] **Step 4: Add the billing block to the view**

In `resources/views/dashboard.blade.php`, add a `subscribed` case to the existing `@switch(session('status'))` block (after the `facebook-connect-failed` case):
```blade
                        @case('subscribed')
                            Subscription active. Thanks!
                            @break
```
Then add a new billing card. Insert it as a new `<div>` inside the `space-y-6` container, after the first stats card (the one closing at the `</div>` before `<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">`):
```blade
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold text-gray-900">Billing</h3>
                    <dl class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <dt class="text-sm text-gray-500">Status</dt>
                            <dd class="font-semibold">
                                @switch($billingStatus)
                                    @case('subscribed')
                                        <span class="text-green-700">Subscribed</span>
                                        @break
                                    @case('trialing')
                                        <span class="text-indigo-700">Trialing</span>
                                        @break
                                    @default
                                        <span class="text-red-700">Lapsed</span>
                                @endswitch
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Replies this month</dt>
                            <dd class="font-semibold">{{ $repliesThisPeriod }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Manage</dt>
                            <dd class="font-semibold">
                                @if ($billingStatus === 'subscribed')
                                    <a href="{{ route('billing.portal') }}" class="text-sm text-indigo-600 underline">Manage billing</a>
                                @else
                                    <a href="{{ route('billing.checkout') }}" class="text-sm text-indigo-600 underline">Subscribe</a>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/DashboardTest.php`
Expected: PASS (all dashboard tests, including the three new ones).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add -A
git commit -m "feat: show billing status and replies count on dashboard"
```

---

## Task 11: Full suite green + final formatting

**Files:** none (verification)

- [ ] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: ALL tests pass (existing + new). If `ProcessIncomingMessageTest` or any channel/webhook test fails, it is due to a signature change from Tasks 5/7 — fix the call site per the relevant task's notes.

- [ ] **Step 2: Format**

Run: `./vendor/bin/pint`
Expected: clean.

- [ ] **Step 3: Final commit (if pint changed anything)**

```bash
git add -A
git commit -m "style: pint formatting for billing feature"
```

---

## Notes on Stripe setup (operator, outside this plan)

These are dashboard/CLI steps the operator performs once; they are not code tasks:
- Create a Product with a flat monthly **base price** → set `STRIPE_PRICE_BASE`.
- Create a **Meter** with event name `ai_replies` and a **metered price** with tiered pricing (tier 1 up to the included quota at $0, tier 2 the overage) → set `STRIPE_PRICE_METERED`.
- Configure the Stripe webhook endpoint to `https://<host>/stripe/webhook` and set `STRIPE_WEBHOOK_SECRET`.
- Enable the customer billing portal in Stripe settings (required for `redirectToBillingPortal`).
