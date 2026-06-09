# Spec 1b — Google Calendar Booking

**Date:** 2026-06-09
**Status:** Approved design, ready for implementation plan
**Depends on:** Spec 1a (conversation engine, `BookingService` interface, `Appointment` model with `external_event_id`)

## Context

Sub-project 1a built a channel-agnostic conversation engine that books appointments
through a `BookingService` interface, with a `FakeBookingService` (in-memory/DB-only
calendar) so the bot is testable end-to-end. 1b replaces the fake with a real Google
Calendar integration behind the same interface, so connected workspaces book into the
owner's actual calendar.

The user has a Google Cloud project (OAuth client + Calendar API). Decisions locked
during brainstorming:

- **Connection:** OAuth, owner connects their Google account, bot uses their **primary**
  calendar.
- **Unconnected workspaces:** booking tools cannot book — they reply that a human will
  follow up and flag handoff. FAQ and handoff still work. (No fake-calendar fallback in
  production.)
- **Libraries:** Laravel Socialite (Google) for the OAuth dance; official
  `google/apiclient` for Calendar (FreeBusy + events).

## Goals

- A workspace owner can connect/disconnect their Google Calendar via OAuth.
- For connected workspaces, the bot computes availability as `BotSetting` hours minus
  Google FreeBusy busy intervals, and books/reschedules/cancels real calendar events.
- Local `Appointment` rows mirror every booking (linked by `external_event_id`), so
  `find`/`reschedule`/`cancel` work from our DB and act on Google by event id.
- The Google SDK is isolated behind a gateway interface so the booking logic is unit
  testable without network calls (mirrors how 1a isolated Claude behind `ClaudeClient`).

## Non-Goals

- Multi-calendar selection (primary only).
- Service-account / shared-calendar connection model.
- Facebook channel, billing, full dashboard (later sub-projects).
- Two-way sync / webhook push notifications from Google (bot writes events; it does not
  subscribe to external calendar changes beyond reading FreeBusy at availability time).

## Architecture

### Components

**Token storage**
- Migration `google_accounts`: `id`, `workspace_id` (unique, FK cascadeOnDelete),
  `access_token` (text), `refresh_token` (text), `expires_at` (datetime nullable),
  `calendar_id` (string, default `primary`), timestamps.
- `GoogleAccount` model: `$fillable` for all above; `access_token` and `refresh_token`
  cast `encrypted`; `expires_at` cast `datetime`; `belongsTo(Workspace::class)`.
- `Workspace`: add `googleAccount(): HasOne`.

**OAuth connect flow**
- Add Socialite (`laravel/socialite`). `config/services.php` `google` block:
  `client_id`, `client_secret`, `redirect` from env. `.env.example` documents
  `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`.
- `GoogleCalendarController`:
  - `connect()` — `Socialite::driver('google')->scopes(['https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.readonly'])->with(['access_type' => 'offline', 'prompt' => 'consent'])->redirect()`.
  - `callback()` — exchange code; upsert a `GoogleAccount` for the owner's workspace with
    access token, refresh token, and expiry; redirect to dashboard with a status flash.
  - `disconnect()` — delete the workspace's `GoogleAccount` row.
- Routes under the existing `auth`+`verified` group: `GET /google/connect`,
  `GET /google/callback`, `DELETE /google/disconnect`.

**Token management**
- `GoogleClientFactory::for(GoogleAccount $account): \Google\Client` — constructs a
  `Google\Client` with the stored access token; if expired (or near expiry) and a refresh
  token exists, refreshes via the refresh token, then persists the new access token +
  `expires_at` back to the `GoogleAccount`. Throws/​signals failure if refresh fails
  (treated as disconnected by the caller).

**Google gateway (isolation seam)**
- Interface `App\Services\Booking\Google\GoogleCalendar`:
  - `freeBusy(GoogleAccount $account, Carbon $from, Carbon $to): array` — list of busy
    `{start, end}` Carbon intervals on the account's `calendar_id`.
  - `insertEvent(GoogleAccount $account, array $event): string` — returns the created
    Google event id. `$event` = `{summary, start, end, attendee_email?}`.
  - `patchEvent(GoogleAccount $account, string $eventId, array $event): void`.
  - `deleteEvent(GoogleAccount $account, string $eventId): void`.
- Real `GoogleApiCalendar implements GoogleCalendar` — wraps `google/apiclient`
  (`Google\Service\Calendar`) using a client from `GoogleClientFactory`. Runtime only;
  not unit tested.
- `FakeGoogleCalendar implements GoogleCalendar` (in `tests/Support`) — records inserts/
  patches/deletes and returns scripted busy intervals; drives all booking-service tests.

**GoogleCalendarBookingService (implements `BookingService`)**
- Constructed with the `GoogleCalendar` gateway.
- `availability(Workspace, Carbon $day)`: generate candidate slots from `BotSetting`
  hours/`default_appointment_duration_minutes`/`buffer_minutes` for that weekday (same
  slot generation as `FakeBookingService`), then exclude any slot overlapping a
  `freeBusy(...)` interval for the day. Requires the workspace's `GoogleAccount`.
- `book(Workspace, Carbon $startsAt, array $customer, ?int $conversationId)`:
  `insertEvent(...)` then create an `Appointment` row (status `booked`,
  `external_event_id` = returned id).
- `find(Workspace, string $contact)`: local `Appointment` rows (booked) by contact.
- `reschedule(Appointment, Carbon $newStartsAt)`: `patchEvent(...)` by
  `external_event_id`, update the `Appointment`.
- `cancel(Appointment)`: `deleteEvent(...)` by `external_event_id`, mark `Appointment`
  cancelled.

**Per-workspace resolution + ToolRegistry refactor**
- `BookingServiceResolver::for(Workspace $w): ?BookingService` — returns a
  `GoogleCalendarBookingService` when `$w->googleAccount` exists, else `null`.
- Refactor `ToolRegistry` to depend on `BookingServiceResolver` (not a single
  `BookingService`). Each booking handler (`check_availability`, `book_appointment`,
  `find_booking`, `reschedule_appointment`, `cancel_appointment`) resolves the service for
  `$context->workspace`; if `null`, it sets `$context->conversation->status = needs_human`
  and returns "The calendar isn't connected yet — a teammate will follow up." FAQ and
  `request_human_handoff` are unaffected.
- `BotServiceProvider`: bind `BookingServiceResolver`; the resolver constructs
  `GoogleCalendarBookingService` with the real `GoogleApiCalendar` gateway. (The 1a
  `FakeBookingService` binding is removed from production wiring; `FakeBookingService`
  remains for its own unit test.)

**Dashboard wiring**
- `DashboardController` currently passes a hardcoded `googleConnected = false`. Wire it to
  `$workspace->googleAccount()->exists()`. Add Connect/Disconnect controls in the
  dashboard (or bot-settings) view pointing at the new routes.

### Data flow (booking)

```
tool handler (book_appointment)
  → BookingServiceResolver::for(workspace)
    → null? → reply "not connected" + status=needs_human
    → GoogleCalendarBookingService
      → GoogleCalendar gateway (GoogleApiCalendar)
        → GoogleClientFactory (authed client, refresh if expired)
        → Calendar API events.insert
      → Appointment row (external_event_id = google event id)
```

## Error handling

- **Token refresh failure / revoked access:** `GoogleClientFactory` signals failure; the
  booking service surfaces it as a tool error. The tool handler treats the workspace as
  effectively disconnected — flags handoff and returns the not-connected message. (Marking
  the account for reconnect can be a follow-up; deleting on hard-revoke is acceptable.)
- **Calendar API errors (FreeBusy/insert/patch/delete):** caught in the booking service,
  re-surfaced so the tool handler returns an `is_error` tool result; the engine's existing
  loop/handoff handling takes over.
- **OAuth callback errors** (user denied, missing code): redirect back with an error flash;
  no `GoogleAccount` row created.

## Testing

- **Booking service** (`GoogleCalendarBookingService` with `FakeGoogleCalendar`):
  - availability excludes slots overlapping scripted busy intervals; respects hours/buffer.
  - `book` calls `insertEvent` and creates an `Appointment` with the returned
    `external_event_id`.
  - `reschedule` calls `patchEvent` and updates the `Appointment`.
  - `cancel` calls `deleteEvent` and marks the `Appointment` cancelled.
- **Resolver / ToolRegistry:** unconnected workspace → resolver returns null → booking
  tool returns the not-connected message and sets conversation `needs_human`; connected
  workspace → booking tool books via the (fake-gateway) Google service.
- **OAuth controller:** `connect` redirects to Google; `callback` with a mocked Socialite
  user persists a `GoogleAccount` (tokens stored, `calendar_id = primary`); `disconnect`
  removes it. Use `Socialite::fake()` / a mocked provider so no real OAuth occurs.
- **Dashboard:** shows connected/disconnected state based on a `GoogleAccount` row.
- All tests follow the existing style (`RefreshDatabase`, real routes, `User::factory()` +
  `CreateWorkspaceForUser`). The real `GoogleApiCalendar` and `GoogleClientFactory` network
  paths are not unit tested — they are exercised at runtime with real credentials.

## Conventions followed

- External SDK isolated behind an interface with a fake for tests (same pattern as 1a's
  `ClaudeClient`).
- Service/resolver classes hold domain logic; controllers stay thin and resolve the active
  workspace via `$request->user()->workspaces()->firstOrFail()`.
- Migrations, models, factories, and feature tests match existing project conventions.

## Open items for the implementation plan

- Exact Socialite Google token field mapping (`refreshToken`, `expiresIn`) and how to
  obtain a refresh token reliably (`prompt=consent`).
- Slot-generation reuse: extract the shared hours→slots logic so `FakeBookingService` and
  `GoogleCalendarBookingService` don't duplicate it, vs. accept minor duplication.
- Whether Connect/Disconnect UI lives on the dashboard or bot-settings page.
