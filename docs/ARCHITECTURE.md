# Architecture

A tour of the system for engineers new to the repo. Starts at the request
boundary, follows a message from Facebook through the bot engine and into
Google Calendar, then back out to the customer, and ends on the Stripe
metered-billing side.

## 1. Tenancy and the workspace model

The workspace — not the user — is the unit that owns everything that matters:

- Stripe billing (the `Billable` model)
- Trial state (`Trial`)
- Bot configuration (`BotSetting`)
- The Facebook Page connection (`FacebookPage`)
- The Google Calendar connection (`GoogleAccount`)
- Conversations, messages, and appointments

A `User` is a person who logs in. A user may belong to many workspaces through
the `workspace_users` pivot, which carries a `role` (currently only `owner` is
ever used). Every controller that needs a workspace resolves it with:

```php
$workspace = $request->user()
    ->workspaces()
    ->with(['trial', 'botSetting', 'facebookPage', 'subscriptions'])
    ->firstOrFail();
```

This is the **first-workspace heuristic**: the call is not filtered on role and
is not ordered. There is no "current workspace" switcher yet. When a user has
exactly one workspace (the case after signup), this is fine. Multi-workspace
support is tracked in `docs/IMPROVEMENTS.md` §P3.

### Signup wires up a full tenant

`RegisteredUserController::store` (auth scaffold) creates the user, fires
`Registered`, then delegates to
`App\Actions\Workspaces\CreateWorkspaceForUser`. That action, in one place:

1. Creates a `Workspace` named `"{$user->name} Workspace"` with a uniquified
   slug (uses a `while (Workspace::where('slug', $candidate)->exists())` loop
   and appends `-2`, `-3`, …).
2. Attaches the user with `role => 'owner'` on `workspace_users`.
3. Opens a 14-day `trialing` `Trial` (`starts_at = now()`,
   `ends_at = now()->addDays(14)`, `status = 'trialing'`).
4. Seeds a disabled `BotSetting` (`business_name = $workspace->name`,
   30-minute default, 0 buffer, **no `booking_hours`**).

When the answer to "what does a new tenant get?" changes, edit the action, not
the controller.

### Can the bot run?

`Workspace::canUseBot(): bool` returns `true` if the trial is active **or** the
workspace is subscribed. The bot engine and webhook receiver both check this.

## 2. The bot engine

The engine is a single class: `App\Services\Bot\BotEngine`. Its job is to take
a `Conversation` plus a new user message, run a Claude tool-use loop, persist
every turn, and return the final reply text.

### Inputs

- `Conversation` — a thread identified by `(workspace_id, channel,
  external_participant_id)`. The unique index on this triplet means
  `firstOrCreate` is the lookup everywhere.
- A user text message.

### System prompt

`SystemPromptBuilder` (`app/Services/Bot/SystemPromptBuilder.php`) reads the
workspace's `BotSetting` and assembles a prompt with: business name, business
description, the booking knobs (duration, buffer, timezone), the
`booking_hours` map, the cancellation instructions, and the `bot_tone`. The
prompt is what tells the model when to call which tool and what tone to use.

### Tool-use loop

`ToolRegistry` (`app/Services/Bot/Tools/ToolRegistry.php`) exposes six tools
to Claude:

| Tool | Purpose |
| --- | --- |
| `check_availability` | Lists open slots via `BookingService` for a given date. |
| `book_appointment` | Inserts a calendar event and an `Appointment` row. |
| `reschedule_appointment` | Patches the event and updates times. |
| `cancel_appointment` | Deletes the event and marks the appointment cancelled. |
| `request_human_handoff` | Flips the conversation `status` to `needs_human` and returns a friendly fallback. |
| `get_business_info` | Generic lookup for hours/description/tone — answered from `BotSetting` without external calls. |

The loop is capped at `MAX_ITERATIONS = 8`. If the model tries to call a tool
more than 8 times in one turn the engine stops and asks for a human.

Every turn is persisted: the user message, the assistant message, and one
`Message` per tool call (with `role='tool'`, `tool_calls` cast to `array`).
This is the audit trail and the context fed back into the next request.

### Return value

The engine returns the final assistant text reply (string). It returns an empty
string — and persists nothing — when `BotSetting::is_enabled` is `false` or
`Workspace::canUseBot()` is `false`.

The caller (`ProcessIncomingMessage`) is responsible for sending the reply
back to the user and recording a `usage_records` row for billing.

## 3. Booking

### Service interface

`App\Services\Booking\BookingService` is the contract every backend implements:

- `availability(CarbonImmutable $from, CarbonImmutable $to, int $durationMin, int $bufferMin, array $bookingHours, string $timezone): array`
- `book(Appointment $a, array $customer): Appointment`
- `reschedule(Appointment $a, CarbonImmutable $start, CarbonImmutable $end): Appointment`
- `cancel(Appointment $a): void`

### Resolver

`BookingServiceResolver` returns the active implementation for a workspace:

- `GoogleCalendarBookingService` when a `GoogleAccount` row exists.
- `FakeBookingService` (in-memory) otherwise — used in tests and as a
  no-network fallback so the engine is exerciseable end-to-end without Google.

### Google backend

`GoogleCalendarBookingService` is the production implementation. Internally:

- `GoogleClientFactory` builds a `Google_Client` with auto-refreshing tokens.
  If the access token is expiring inside the next minute, it refreshes using
  the stored `refresh_token` and persists the new pair.
- `GoogleApiCalendar` is the gateway to the Google Calendar API
  (`freeBusy`, `insertEvent`, `patchEvent`, `deleteEvent`).
- `SlotGenerator` turns `BotSetting::booking_hours` (a map of weekday →
  `[{start, end}]` in `HH:MM`) into candidate slots in the workspace timezone,
  stepping by `duration + buffer` minutes, and excludes any slot that overlaps
  a busy window from `freeBusy`.

`FakeGoogleCalendar` (`tests/Support/`) and `FakeBookingService` keep tests
network-free.

## 4. The inbound flow

A customer DMs a connected Facebook Page. The full path:

```
Facebook
  → POST /webhooks/facebook
  → FacebookWebhookController::receive
      • verify X-Hub-Signature-256 (HMAC over raw body, sha256, client_secret)
      • dedup on WebhookEvent keyed by event_id (the Facebook mid)
      • resolve FacebookPage by page_id
      • gate on botSetting.is_enabled && workspace.canUseBot()
      • dispatch ProcessIncomingMessage
  → ProcessIncomingMessage (queue)
      • load workspace, find-or-create Conversation (channel=facebook)
      • run BotEngine → reply text
      • ChannelResolver::for('facebook') → FacebookMessengerChannel
          • persist assistant Message
          • POST sendMessage to Graph API
      • UsageRecorder
          • write usage_records (message_id is the dedup key)
          • if subscribed: call StripeUsageMeter + stamp reported_at
```

### Webhook safety

- The webhook routes (`webhooks/facebook` GET/POST) are **excluded from CSRF**
  in `bootstrap/app.php`.
- HMAC verification uses the raw body (`$request->getContent()`) and
  `hash_equals`. Wrong or missing signature → 403.
- `WebhookEvent` dedup on `event_id` (the Facebook `mid`) means a single
  message retried by Facebook will not trigger duplicate processing.

### Web chat tester

`ChatTesterController` lets the workspace owner drive the same engine from
the browser. It uses `channel='web'`, `participantId='tester-'.$user->id`,
and `WebChatChannel` (which persists a `Message` but does not call any
external API). It calls `ProcessIncomingMessage::dispatchSync` so the reply
is ready on the redirect — the queue is bypassed here deliberately.

## 5. Billing

Laravel Cashier is set up with the workspace as the `Billable` model
(`Cashier::useCustomerModel(Workspace::class)` in
`BotServiceProvider::register()`).

### Subscription

`BillingController::checkout` returns a Cashier `Checkout` response built from
`$workspace->newSubscription('default', [services.stripe.price_base])
->meteredPrice(services.stripe.price_metered)->checkout(...)`. The customer
pays the base price for the seat and is metered for AI replies on top.

`BillingController::portal` redirects to Cashier's hosted billing portal.

### Usage ledger

The local source of truth for usage is `usage_records`. Each row pins
`message_id` (unique) so duplicate calls into the recorder are idempotent.

`UsageRecorder` (`app/Services/Billing/UsageRecorder.php`) is called by
`ProcessIncomingMessage` after the engine reply is persisted:

1. Always: write a `usage_records` row with the message id.
2. If the workspace is subscribed: call `StripeUsageMeter` to push a meter
   event to Cashier, and stamp `reported_at`.
3. If on trial: skip the Stripe push; `reported_at` stays null.

`UsageMeter` is the interface; `StripeUsageMeter` is the production
implementation. `FakeUsageMeter` (`tests/Support/`) is bound in `testing()`.

### Status on the dashboard

`DashboardController::__invoke` derives a `billingStatus` string:

- `subscribed` — `workspace->subscribed('default')` is true
- `trialing` — the `Trial` is active
- `lapsed` — neither

It also counts `usageRecords()->where('created_at', '>=',
now()->startOfMonth())->count()` and passes that to the view as
`repliesThisPeriod`.

## 6. Service provider and DI

`App\Providers\BotServiceProvider` is where the third-party service contracts
get bound:

- `register()` — `Cashier::useCustomerModel(Workspace::class)`.
- `boot()` — nothing of note.
- `testing()` — binds the fakes in `tests/Support/` (Claude, Google Calendar,
  Facebook Graph, Usage Meter) **only** when `app->environment('testing')`.
  Production never sees these fakes.

`AppServiceProvider` is otherwise stock.

## 7. The directory layout

```
app/
  Actions/                     # one invokable per multi-step flow
    Workspaces/
      CreateWorkspaceForUser.php
  Http/
    Controllers/               # one controller per concern (thin)
    Requests/                  # only Auth/LoginRequest, ProfileUpdateRequest
  Jobs/
    ProcessIncomingMessage.php
  Models/                      # 12 Eloquent models
  Providers/
    AppServiceProvider.php
    BotServiceProvider.php
  Services/
    Billing/                   # UsageRecorder, UsageMeter (interface), StripeUsageMeter
    Booking/                   # BookingService contract, resolver, Fake, Google backend
      Google/                  # GoogleClientFactory, GoogleApiCalendar, GoogleCalendar interface
    Bot/                       # BotEngine, SystemPromptBuilder, Claude client
      Claude/                  # AnthropicClaudeClient, ClaudeClient interface
      Tools/                   # ToolRegistry, ToolContext
    Channels/                  # ChannelResolver + per-channel impls
    Facebook/                  # FacebookGraph interface + HttpFacebookGraph
  View/
    Components/                # Breeze component aliases
resources/
  views/
    auth/                      # Breeze auth screens
    bot-settings/edit.blade.php
    chat-tester/index.blade.php
    components/                # Breeze form/button primitives
    layouts/                   # app, guest, navigation
    profile/                   # Breeze profile editor + partials
    dashboard.blade.php
    welcome.blade.php
routes/
  web.php                      # all product routes
  auth.php                     # Breeze auth routes
  console.php                  # scheduler/console entry
tests/
  Feature/                     # 29 files, real HTTP, RefreshDatabase
  Unit/                        # 6 files, pure logic
  Support/                     # fakes for Claude/Google/Facebook/UsageMeter
docs/
  ARCHITECTURE.md              # this file
  IMPROVEMENTS.md              # prioritized issues
  superpowers/
    specs/                     # design history per sub-project
    plans/                     # TDD plans that drove each sub-project
```

## 8. End-to-end trace

A worked example: a customer DMs "Can I book a haircut next Tuesday at 3pm?"

1. **Facebook → webhook.** Facebook POSTs to
   `POST /webhooks/facebook`. `FacebookWebhookController::receive` verifies
   the HMAC, records a `WebhookEvent` with `event_id = mid`, finds the
   `FacebookPage` (and through it the workspace), and dispatches
   `ProcessIncomingMessage` on the queue.
2. **Job → engine.** `ProcessIncomingMessage::handle` loads the workspace,
   `firstOrCreate`s the `Conversation` keyed on
   `(workspace_id, channel='facebook', external_participant_id=sender_id)`,
   appends the user message, and calls `BotEngine::reply`.
3. **Engine → Claude.** The engine builds the system prompt from
   `BotSetting` and calls Claude. The model emits a tool call to
   `check_availability` for the requested date. The engine dispatches it
   through `ToolRegistry` → `BookingService` → `GoogleCalendarBookingService`
   → `GoogleApiCalendar::freeBusy`. `SlotGenerator` produces candidate slots;
   busy windows are excluded. The model sees the result and emits a tool
   call to `book_appointment`.
4. **Booking.** `book_appointment` calls `GoogleApiCalendar::insertEvent` and
   inserts an `Appointment` row with `external_event_id` set. The model
   then emits the final assistant text — "You're booked for Tuesday at 3pm.
   See you then!"
5. **Reply.** `ChannelResolver::for('facebook')` returns
   `FacebookMessengerChannel`, which `sendReply`s to Graph API and persists
   the assistant `Message`.
6. **Billing.** `UsageRecorder` writes a `usage_records` row. If subscribed,
   it also calls `StripeUsageMeter` and stamps `reported_at`. On trial, the
   local row is the only artifact.
7. **Customer.** A confirmation lands in the customer's Messenger thread.

The same engine is reachable from the browser via `/chat-tester` (channel =
`web`, participant = `'tester-'.$user->id`), which runs the job synchronously
and uses `WebChatChannel` in place of `FacebookMessengerChannel`.
