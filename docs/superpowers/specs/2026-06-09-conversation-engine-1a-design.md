# Spec 1a — Channel-Agnostic Conversation Engine (with fake calendar)

**Date:** 2026-06-09
**Status:** Approved design, ready for implementation plan
**Branch context:** `codex/saas-foundation`

## Context

The product is a micro-SaaS where a business owner connects a Facebook Page to an
AI bot that books appointments from Messenger conversations. The repo currently has
the SaaS foundation only: auth, multi-tenant `Workspace`s, `Trial`s, and `BotSetting`
configuration. None of the actual product (Facebook, the bot, calendar booking) exists
yet.

"Finish the project" decomposes into six independent subsystems. We are building them
**core-first** so every step is runnable with the credentials currently available
(Anthropic API key + Google Cloud project; no Meta app, no Stripe yet). The agreed
build order:

1. **Bot engine** (this spec, 1a) — channel-agnostic, fake calendar
2. **Google Calendar booking** (1b) — real `BookingService` swapped behind the same interface
3. **Facebook connect + Messenger webhook** — channel adapter feeding the same engine, once a Meta app exists
4. **Persistence + dashboard** — surface real conversations/appointments
5. **Billing** — Stripe/Cashier; trial enforcement can land earlier

This spec covers **sub-project 1a**: a channel-agnostic conversation engine that books
appointments, testable end-to-end through a local web-chat page, with a fake in-memory
calendar standing in for Google Calendar. Subsystem 1b replaces the fake with the real
Google implementation behind the same `BookingService` interface — no engine rework.

## Goals

- A working bot brain: a customer can FAQ, book, reschedule, cancel, or be handed off
  to a human, all in one conversation.
- Channel-agnostic: the engine takes a conversation + text and returns a reply; it
  does not know or care that the channel is web chat (today) or Facebook (later).
- Queued processing so the same engine drops behind a webhook later with no rework.
- Fully testable today with no real external API calls.

## Non-Goals (deferred to later sub-projects)

- Real Google Calendar integration (1b).
- Facebook/Messenger connect and webhook (subsystem 3).
- Billing and hard trial enforcement (subsystem 5) — the engine checks `is_enabled`
  and trial status, but payment flows are out of scope.
- A full conversations/appointments dashboard (subsystem 4).

## Architecture

### Bot abilities (v1 scope)

All four agreed abilities: **answer FAQ**, **book**, **reschedule/cancel**, **handoff to human**.

### Components

**Models + migrations**

- `Conversation` — `workspace_id`, `channel` (e.g. `web`, later `facebook`),
  `external_participant_id` (per-channel customer identity; a test session id for web
  chat, a PSID for Facebook later), `status` (`active`, `needs_human`, `closed`).
  Unique on (`workspace_id`, `channel`, `external_participant_id`).
- `Message` — `conversation_id`, `role` (`user`, `assistant`, `tool`), `content` (text),
  and a nullable JSON column for tool-call metadata (tool name, input, result).
- `Appointment` — `workspace_id`, `conversation_id`, `customer_name`,
  `customer_contact`, `starts_at`, `ends_at`, `status` (`booked`, `cancelled`),
  and `external_event_id` (nullable; populated by the Google implementation in 1b).

**`ConversationChannel` interface**

Abstraction over how a reply reaches the customer.

- `sendReply(Conversation $conversation, string $text): void`
- 1a implementation: `WebChatChannel` — persists the assistant message so the web-chat
  UI can render it (and returns it to the request for synchronous display).
- Future: `FacebookChannel` — sends via the Messenger Send API. Same interface.

**`BotEngine` service**

The brain. Single entry point:

- `handle(Conversation $conversation, string $userText): string` — appends the user
  message, builds the system prompt, runs the Claude tool-use loop, persists assistant
  and tool messages, returns the assistant's final text.
- Builds the system prompt from the workspace's `BotSetting`: `business_name`,
  `business_description`, `bot_tone`, `booking_hours`, `default_appointment_duration_minutes`,
  `buffer_minutes`, `cancellation_instructions`. Injects the current date/time in the
  workspace timezone on each turn (not in a cached prefix).
- Iteration guard: cap the tool loop at ~8 iterations to prevent runaways.

**Tools (custom, client-side)**

Six tools, each backed by a service method. Tool input/output schemas are explicit, and
descriptions state *when* to call each tool (recent Opus models reach for tools
conservatively):

- `check_availability(date_range)` → open slots
- `book_appointment(starts_at, customer_name, customer_contact)` → confirmation
- `find_booking(customer_contact)` → existing appointment(s) for this customer
- `reschedule_appointment(appointment_id, new_starts_at)`
- `cancel_appointment(appointment_id)`
- `request_human_handoff(reason)` → sets `Conversation.status = needs_human`

**`BookingService` interface**

The booking surface the tools call. Methods:

- `availability(Workspace $w, CarbonPeriod $range): array` — slots
- `book(Workspace $w, Carbon $startsAt, array $customer): Appointment`
- `find(Workspace $w, string $customerContact): Collection`
- `reschedule(Appointment $a, Carbon $newStartsAt): Appointment`
- `cancel(Appointment $a): void`

1a implementation: **`FakeBookingService`** — computes availability from `BotSetting`
booking hours, minus appointments already stored in the DB, minus `buffer_minutes`,
using `default_appointment_duration_minutes` for slot size. Writes/updates `Appointment`
rows. No external calls.

1b will bind **`GoogleCalendarBookingService`** to the same interface in the service
container; the engine and tools do not change.

**`ProcessIncomingMessage` job (queued)**

- Input: workspace, channel name, `external_participant_id`, message text.
- Resolves or creates the `Conversation`, calls `BotEngine::handle`, sends the reply
  through the resolved `ConversationChannel`.
- Queued so a future Facebook webhook can respond fast and let the job do the work.
  For the web-chat tester, the `sync` queue (or dispatching and awaiting) is fine.

**Local web-chat tester**

- Route `/chat-tester` (behind `auth` + `verified`, scoped to the owner's workspace).
- Blade + Alpine page: a message input and a transcript view.
- POST a message → dispatch `ProcessIncomingMessage` (sync) → return the assistant reply
  → render. This is the "simulated inbox" that proves the whole engine without Meta.

### Data flow

```
web-chat POST
  → ProcessIncomingMessage (job)
    → BotEngine::handle(conversation, text)
      → Claude tool-use loop
        → tools call BookingService (FakeBookingService in 1a)
      → assistant text
    → persist messages
  → ConversationChannel::sendReply (WebChatChannel)
  → rendered in chat UI
```

## Claude API integration

- Official PHP SDK `anthropic-ai/sdk`.
- Model `claude-opus-4-8`, `thinking: {type: "adaptive"}`, `output_config: {effort: "high"}`.
- Tools are custom client-side tools; use the SDK tool runner or a manual loop —
  whichever the plan finds cleaner in PHP — with the iteration guard above.
- `ANTHROPIC_API_KEY` in `.env` (add to `.env.example`).
- The Anthropic client is resolved from the service container so tests can bind a fake.

## Error handling

- Claude API errors → catch typed SDK exceptions, log, return a graceful fallback reply
  ("Sorry, I'm having trouble right now — someone will follow up shortly") and set the
  conversation to `needs_human`.
- Tool execution errors → return a tool result with `is_error: true` so the model can
  adapt rather than crashing the loop.
- `BotSetting.is_enabled = false`, or the workspace's trial not in a usable state → the
  bot does not respond; the web-chat tester surfaces a "bot disabled" state.

## Testing

- **Fake Anthropic client** bound into the container — no test hits the real API. The
  fake returns scripted tool-use sequences so flows are deterministic.
- **Feature tests** (use `RefreshDatabase`, exercise real HTTP routes — matches the
  existing `tests/Feature` style):
  - Booking flow: a scripted conversation creates an `Appointment`.
  - Availability respects `booking_hours`, `default_appointment_duration_minutes`, and
    `buffer_minutes`; already-booked slots are excluded.
  - Handoff: `request_human_handoff` sets `Conversation.status = needs_human`.
  - Disabled bot (`is_enabled = false`) does not reply.
- **Unit tests:** `FakeBookingService` availability computation; system-prompt builder
  output for a given `BotSetting`.

## Conventions followed

- Multi-step atomic domain logic lives in service/action classes (mirrors
  `App\Actions\Workspaces\CreateWorkspaceForUser`), not controllers.
- The active workspace is resolved via `$request->user()->workspaces()->...->firstOrFail()`,
  consistent with `DashboardController` / `BotSettingsController`.
- Server-rendered Blade + Tailwind v4 + Alpine; no SPA.

## Open items for the implementation plan

- Whether to use the PHP SDK tool runner vs a manual agentic loop.
- Exact JSON tool schemas and the slot representation returned by `check_availability`.
- Migration ordering and foreign-key cascade choices for the new tables.
