# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A micro-SaaS where business owners connect a Facebook Page and a Google Calendar to an
AI bot that handles Messenger-driven appointment booking. Laravel 12 app scaffolded
from Laravel Breeze (Blade + Alpine.js + Tailwind v3).

The current state covers the full sub-projects 1a/1b/1c and Stripe usage-billing
flow end-to-end:

- Auth (Breeze), multi-tenant workspaces, 14-day trial, Stripe subscription
- `BotSetting` (business name, hours, duration, buffer, tone, cancellation copy)
- Bot engine driven by Claude, with a six-tool registry and a max-iteration guard
- Facebook Page OAuth + Messenger webhook + `FacebookMessengerChannel`
- Google Calendar OAuth + booking service + `SlotGenerator`
- Usage records + Cashier metered billing
- A web "chat tester" route to exercise the engine without Facebook

The complete design history lives under `docs/superpowers/specs/` and
`docs/superpowers/plans/`. The system overview for engineers new to the repo is
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Known issues and recommended next
work are tracked in [`docs/IMPROVEMENTS.md`](docs/IMPROVEMENTS.md).

## Commands

```bash
composer dev                  # run server + queue worker + log tailer (pail) + vite concurrently
composer test                 # clears config cache, then runs the full suite
php artisan test                                  # run all tests
php artisan test --filter test_name_or_class      # run a single test or test class
php artisan test tests/Feature/BotSettingsTest.php # run one file
./vendor/bin/pint             # format PHP (Laravel Pint)
npm run dev                   # vite dev server only
npm run build                 # production asset build
```

Database is SQLite. Tests run against an in-memory SQLite DB (`phpunit.xml`) and use
`RefreshDatabase`, so no migration step is needed before testing.

## Architecture

**Tenancy model.** A `User` belongs to many `Workspace`s through the `workspace_users`
pivot, which carries a `role` (currently only `owner`). The workspace — not the user —
is the unit that owns billing, integrations, configuration, and conversations. Each
workspace `hasOne` `Trial`, `hasOne` `BotSetting`, `hasOne` `FacebookPage`, and
`hasOne` `GoogleAccount` (all enforced unique on `workspace_id` at the DB level). The
workspace is also the `Billable` model — `Cashier::useCustomerModel(Workspace::class)`
is registered in `BotServiceProvider::register()`.

**Signup wires up a full tenant.** `RegisteredUserController::store` creates the user,
fires `Registered`, then delegates to `App\Actions\Workspaces\CreateWorkspaceForUser`,
which in one place creates the workspace (with a uniquified slug), attaches the user
as `owner`, opens a 14-day `trialing` `Trial`, and seeds a disabled `BotSetting`
(business name set to the workspace name, 30-min default, 0 buffer, no `booking_hours`).
When changing what a new tenant gets, edit this action — not the controller.

**Reading the current workspace.** Controllers resolve the active workspace with
`$request->user()->workspaces()->...->firstOrFail()` (see `DashboardController`,
`BotSettingsController`, `ChatTesterController`, `FacebookController`,
`GoogleCalendarController`). There is no "current workspace" switcher yet — the
first workspace is assumed, and the role column is not filtered on. Keep this in
mind when adding multi-workspace support (see `docs/IMPROVEMENTS.md` §P3).

**Bot engine.** `BotEngine` (`app/Services/Bot/BotEngine.php`) takes a `Conversation`
plus the new user message, builds a system prompt via `SystemPromptBuilder`, runs a
Claude tool-use loop capped at `MAX_ITERATIONS = 8`, persists every turn (user,
assistant, and tool messages) to the `messages` table, and returns the final reply.
The tool surface is in `ToolRegistry` (`app/Services/Bot/Tools/ToolRegistry.php`)
and includes `check_availability`, `book_appointment`, `reschedule_appointment`,
`cancel_appointment`, `request_human_handoff`, plus a generic `get_business_info`.
The engine returns an empty string and persists nothing when `BotSetting::is_enabled`
is `false` or `Workspace::canUseBot()` is `false` (trial expired and not
subscribed).

**Booking.** `BookingServiceResolver` returns the active `BookingService` for a
workspace: `GoogleCalendarBookingService` when a `GoogleAccount` exists, else
`FakeBookingService` (in-memory, used in tests and as a no-network fallback).
`GoogleCalendarBookingService` uses `GoogleClientFactory` to build a `Google_Client`
with auto-refreshing tokens, `GoogleApiCalendar` as the Graph/Calendar gateway, and
`SlotGenerator` to turn `BotSetting::booking_hours` into candidate slots that exclude
busy windows from `freeBusy`.

**Inbound flow.** A Facebook webhook hits `FacebookWebhookController::receive`,
verifies `X-Hub-Signature-256`, dedupes on `WebhookEvent` keyed by `event_id` (the
Facebook `mid`), then dispatches `ProcessIncomingMessage` on the queue. The job
loads the workspace, looks up the conversation by `(workspace, channel, participant)`,
runs `BotEngine`, sends the reply through `ChannelResolver` (which returns
`FacebookMessengerChannel` for `channel='facebook'`, `WebChatChannel` for
`channel='web'`), and records a `usage_records` row for billing.

**Billing.** `UsageRecorder` writes a local `usage_records` row on every
non-empty assistant reply (`message_id` is the dedup key). On reply, if the
workspace is subscribed, the recorder also calls `StripeUsageMeter` to push a
meter event to Cashier and stamps `reported_at`. Trials skip the Stripe push.
The "replay unreported rows" scheduled job called out in the billing spec is
intentionally out of scope — see `docs/IMPROVEMENTS.md` §P4.

**Bot configuration shape.** `BotSetting` holds the booking knobs the bot uses:
`is_enabled` (master switch), `business_name`, `business_description`,
`default_appointment_duration_minutes`, `booking_timezone`, `booking_hours` (cast
to `array`/JSON, consumed by `SlotGenerator`), `buffer_minutes`,
`cancellation_instructions`, `bot_tone`. Validation rules live inline in
`BotSettingsController::update`. **Note:** the web form does not yet expose
`booking_hours` — the field exists in the DB and is required for booking to work,
but the only way to set it is the seeded default (null) or direct DB writes. See
`docs/IMPROVEMENTS.md` §P0 #2.

**Auth** is stock Breeze (`routes/auth.php`, `app/Http/Controllers/Auth/`). App routes
live in `routes/web.php` behind `auth`/`verified` middleware, except the Facebook
webhook (`webhooks/facebook` GET/POST) which is CSRF-exempt in
`bootstrap/app.php`.

## Conventions

- Multi-step domain logic that must stay atomic across a flow goes in an invokable/
  `handle()` Action class under `app/Actions/`, not in the controller (see the
  workspace creation pattern above). When the next multi-step flow is added
  (e.g. resubscribe after lapse, handoff to a human inbox), put it here.
- Anything that talks to a third party is a `*Graph` / `*Calendar` / `*Client`
  interface in `app/Services/` with a real HTTP implementation and a
  `tests/Support/Fake*.php`. Tests inject the fake; production binds the HTTP
  implementation in `BotServiceProvider`. Follow this pattern for any new
  external service.
- Feature tests use `RefreshDatabase` and exercise real HTTP routes
  (`$this->post('/register', ...)`) rather than calling controllers directly. The
  fakes in `tests/Support/` are bound through `BotServiceProvider`'s
  `testing()` method when `app()->environment('testing')`. Match this style.
- Frontend is server-rendered Blade with Tailwind v3 and Alpine.js — no SPA
  framework. (`package.json` currently lists both `tailwindcss ^3.1.0` and the
  unused `@tailwindcss/vite ^4.0.0` — see `docs/IMPROVEMENTS.md` §P0 #3.)
