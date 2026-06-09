# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A micro-SaaS where business owners connect a Facebook Page to an AI bot that handles
appointment booking conversations. Laravel 12 app scaffolded from Laravel Breeze
(Blade + Alpine.js + Tailwind v4). Currently at the SaaS foundation stage: auth,
multi-tenant workspaces, trials, and bot settings exist; Facebook/Google integrations
and the bot itself are stubbed (`facebookConnected`/`googleConnected` are hardcoded
`false` in `DashboardController`).

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
is the unit that owns billing and configuration. Each workspace `hasOne` `Trial` and
`hasOne` `BotSetting` (both enforced unique on `workspace_id` at the DB level).

**Signup wires up a full tenant.** `RegisteredUserController::store` creates the user,
then delegates to `App\Actions\Workspaces\CreateWorkspaceForUser`, which in one place
creates the workspace (with a uniquified slug), attaches the user as `owner`, opens a
14-day `trialing` `Trial`, and seeds a disabled `BotSetting`. When changing what a new
tenant gets, edit this action — not the controller.

**Reading the current workspace.** Controllers resolve the active workspace with
`$request->user()->workspaces()->...->firstOrFail()` (see `DashboardController`,
`BotSettingsController`). There is no "current workspace" switcher yet — the first
workspace is assumed. Keep this in mind when adding multi-workspace support.

**Bot configuration shape.** `BotSetting` holds the booking knobs the future bot will
use: `business_name`/`business_description`, `default_appointment_duration_minutes`,
`booking_timezone`, `booking_hours` (cast to `array`/JSON), `buffer_minutes`,
`cancellation_instructions`, `bot_tone`, and the `is_enabled` master switch.
Validation rules live inline in `BotSettingsController::update`.

**Auth** is stock Breeze (`routes/auth.php`, `app/Http/Controllers/Auth/`). App routes
live in `routes/web.php` behind `auth`/`verified` middleware.

## Conventions

- Multi-step domain logic that must stay atomic across a flow goes in an invokable/
  `handle()` Action class under `app/Actions/`, not in the controller (see the workspace
  creation pattern above).
- Feature tests use `RefreshDatabase` and exercise real HTTP routes (`$this->post('/register', ...)`)
  rather than calling controllers directly. Match this style.
- Frontend is server-rendered Blade with Tailwind v4 (via `@tailwindcss/vite`) and
  Alpine.js — no SPA framework.
