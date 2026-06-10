# Facebook Messenger Integration (Spec 1c)

## Summary

The final integration sub-system: connect a Facebook Page to a workspace, receive
Page messages via webhook, run them through the existing `BotEngine`, and reply
through the Messenger Send API. This closes the loop described in the master spec
(`2026-06-02-facebook-page-calendar-saas-design.md`) — the bot and Google Calendar
booking already exist; only the Facebook channel is stubbed
(`facebookConnected` is hardcoded `false` in `DashboardController`).

Scope is full end-to-end: OAuth Page connect/disconnect, webhook verification +
signature-checked receiver with idempotency, page→workspace mapping, the outbound
Messenger reply channel, and real dashboard connection state.

## Context and Existing Patterns

This spec deliberately mirrors patterns already established by the Google Calendar
work (spec 1b) so the codebase stays consistent:

- **Per-workspace encrypted token model.** `GoogleAccount` (`hasOne` per workspace,
  `encrypted` casts on tokens). Facebook gets `FacebookPage` the same way.
- **OAuth via Socialite.** `GoogleCalendarController` uses the Socialite `google`
  driver. Facebook uses the Socialite `facebook` driver.
- **Gateway interface + Fake.** `GoogleCalendar` interface, `GoogleApiCalendar`
  (real), bound in `BotServiceProvider`; tests swap a fake. Facebook gets
  `FacebookGraph` + `HttpFacebookGraph` + `FakeFacebookGraph`.
- **Resolver keyed per workspace/conversation.** `BookingServiceResolver->for($workspace)`.
  Facebook introduces `ChannelResolver->for($conversation)`.

The message pipeline already exists and is channel-agnostic:
`ProcessIncomingMessage(workspaceId, channel, participantId, text)` →
`BotEngine->handle($conversation, $text)` → `ConversationChannel->sendReply()`.
The web chat tester drives it today with `channel = 'web'`. Facebook adds a second
channel string, `'facebook'`, and a second `ConversationChannel` implementation.

## Data Model

Two migrations and one model.

### `facebook_pages` table

| column | type | notes |
|--------|------|-------|
| `id` | bigint pk | |
| `workspace_id` | fk → workspaces, unique | one page per workspace |
| `page_id` | string, unique, indexed | Graph Page id; webhook lookup key |
| `page_name` | string | display only |
| `page_access_token` | text | `encrypted` cast |
| `user_access_token` | text, nullable | `encrypted` cast; long-lived user token |
| `subscribed_at` | timestamp, nullable | set when app is subscribed to the page |
| timestamps | | |

### `webhook_events` table

Idempotency ledger so duplicate Facebook deliveries do not double-process.

| column | type | notes |
|--------|------|-------|
| `id` | bigint pk | |
| `provider` | string | `'facebook'` (kept generic for future providers) |
| `event_id` | string, unique | the message `mid` |
| `processed_at` | timestamp, nullable | |
| timestamps | | |

Uniqueness is enforced at the DB level on `event_id`; a duplicate insert is the
dedup signal.

### `FacebookPage` model

- `belongsTo(Workspace)`.
- `casts`: `page_access_token => encrypted`, `user_access_token => encrypted`,
  `subscribed_at => datetime`.
- `Workspace` gains `hasOne(FacebookPage)` as `facebookPage()`.

## Graph Gateway

`App\Services\Facebook\FacebookGraph` interface isolates every Graph/Send API call
so services are testable without network access.

```php
interface FacebookGraph
{
    public function exchangeForLongLivedToken(string $userToken): string;

    /** @return array<int, array{id: string, name: string, access_token: string}> */
    public function pages(string $userToken): array;

    public function subscribeApp(string $pageId, string $pageToken): void;

    public function sendMessage(string $pageId, string $pageToken, string $recipientId, string $text): void;
}
```

- **`HttpFacebookGraph`** — real implementation using Laravel's `Http` client against
  the Graph API. Graph version comes from `config('services.facebook.graph_version')`.
  - `exchangeForLongLivedToken`: `GET /oauth/access_token` with
    `grant_type=fb_exchange_token`.
  - `pages`: `GET /me/accounts` (returns id, name, and a page access token per page).
  - `subscribeApp`: `POST /{page-id}/subscribed_apps` with
    `subscribed_fields=messages`.
  - `sendMessage`: `POST /{page-id}/messages` with
    `{recipient:{id}, message:{text}, messaging_type:'RESPONSE'}`.
- **`FakeFacebookGraph`** — records all calls, returns canned pages. Used in tests.
- Bound `FacebookGraph => HttpFacebookGraph` in `BotServiceProvider`.

## Connect Flow

`FacebookController` with `connect`, `callback`, `disconnect`, registered under the
existing `auth`/`verified` route group, mirroring the Google routes.

- **`connect`** — `Socialite::driver('facebook')->scopes(['pages_show_list',
  'pages_messaging', 'pages_manage_metadata'])->redirect()`.
- **`callback`** —
  1. If `error` query present or `code` missing → redirect dashboard with
     `status = 'facebook-connect-failed'`.
  2. Get the Socialite user (short-lived user token).
  3. `graph->exchangeForLongLivedToken()` → long-lived user token.
  4. `graph->pages(longLivedUserToken)` → **auto-pick the first page** (scope is one
     page per workspace). If the list is empty → redirect with
     `status = 'facebook-connect-failed'`.
  5. `graph->subscribeApp(pageId, pageToken)`.
  6. `updateOrCreate` the `FacebookPage` row (page id, name, page token, long-lived
     user token, `subscribed_at = now()`).
  7. Redirect dashboard with `status = 'facebook-connected'`.
- **`disconnect`** — delete the workspace's `FacebookPage` row, redirect with
  `status = 'facebook-disconnected'`.

## Webhook

`FacebookWebhookController` with `verify` (GET) and `receive` (POST), registered
**outside** the `auth` middleware and **excluded from CSRF verification** (the caller
is Facebook, not a browser session).

- **GET `/webhooks/facebook`** (verify, called once at subscription time):
  - If `hub.mode === 'subscribe'` and `hub.verify_token === config('services.facebook.verify_token')`
    → return `hub.challenge` as plain text, 200.
  - Otherwise → 403.
- **POST `/webhooks/facebook`** (receive):
  1. **Signature check first.** Compute `'sha256=' . hash_hmac('sha256', rawBody,
     config('services.facebook.client_secret'))` and `hash_equals` it against the
     `X-Hub-Signature-256` header. Mismatch or missing → 403, no processing.
  2. Parse `entry[].messaging[]` events. For each text message event:
     - Map `entry.id` (page id) → workspace via `FacebookPage`. Unknown page →
       ack 200 and skip (no row created).
     - Dedup: attempt to insert a `webhook_events` row for the message `mid`. If it
       already exists → skip this event.
     - **Gate:** dispatch only if `workspace.botSetting.is_enabled` is true AND the
       trial is active. Otherwise skip (the message is acknowledged but not auto-replied).
     - `ProcessIncomingMessage::dispatch($workspace->id, 'facebook', $senderId, $text)`.
  3. Always return 200 quickly; all bot work happens on the queue.

A `Trial->isActive()` helper is added (`status === 'trialing'` and `ends_at` in the
future) and used by the gate. This satisfies the master spec rule that a disabled bot
**or** an expired trial stops automated replies. `BotEngine` already short-circuits on
`is_enabled`; the webhook gate additionally prevents enqueuing work for expired trials.

## Outbound Channel and Resolver

- **`FacebookMessengerChannel implements ConversationChannel`** — on `sendReply`:
  1. Skip empty text (matches `WebChatChannel`).
  2. Persist the assistant message on the conversation (so dashboard history shows
     outbound replies, same as `WebChatChannel`).
  3. Look up the workspace's `FacebookPage`; call
     `graph->sendMessage(pageId, pageToken, conversation.external_participant_id, text)`.
- **`ChannelResolver->for(Conversation): ConversationChannel`** — returns
  `WebChatChannel` for `'web'` and `FacebookMessengerChannel` for `'facebook'`.
  Mirrors `BookingServiceResolver`.
- **`ProcessIncomingMessage`** — stop injecting `ConversationChannel` from the
  container; resolve it via `ChannelResolver->for($conversation)` after the
  conversation is loaded/created. The existing container binding
  (`ConversationChannel => WebChatChannel`) becomes unnecessary for the job but is
  left in place harmlessly; the web tester path is unchanged in behavior.

## Dashboard and Config

- `DashboardController`: replace the hardcoded `facebookConnected => false` with
  `$workspace->facebookPage()->exists()`.
- Dashboard view: add a Facebook connection block (connect / disconnect, status badge)
  mirroring the existing Google block, plus handling of the new `facebook-*` status
  flash messages.
- `config/services.php` `facebook` entry:
  - `client_id` — `env('FACEBOOK_CLIENT_ID')` (Socialite also reads this).
  - `client_secret` — `env('FACEBOOK_CLIENT_SECRET')`; doubles as the app secret for
    `X-Hub-Signature-256` verification.
  - `redirect` — `env('FACEBOOK_REDIRECT_URI')`.
  - `verify_token` — `env('FACEBOOK_WEBHOOK_VERIFY_TOKEN')`.
  - `graph_version` — `env('FACEBOOK_GRAPH_VERSION', 'v21.0')`.

## Error Handling

- Webhook signature is verified before any parsing; failures return 403 and do nothing.
- Unknown page ids are acknowledged (200) but ignored — no workspace, no work.
- Duplicate deliveries are deduped on the message `mid` via the unique
  `webhook_events.event_id`, so no duplicate replies or bookings.
- Connect failures (OAuth error, no managed pages) redirect with a failure status
  rather than throwing.
- `BotEngine` already handles tool-loop guards and downstream errors (sets
  `needs_human`, returns a safe fallback). Booking idempotency is already handled by
  the existing Google booking service.

## Testing

Feature tests using `RefreshDatabase`, exercising real HTTP routes, with
`FakeFacebookGraph` bound in the container:

- Webhook GET verify returns the challenge for a correct verify token.
- Webhook GET verify returns 403 for a wrong/missing verify token.
- Webhook POST returns 403 when the `X-Hub-Signature-256` signature is invalid/missing.
- Webhook POST maps `page_id` → workspace and dispatches `ProcessIncomingMessage`
  with `channel = 'facebook'`.
- Duplicate webhook delivery (same `mid`) processes once.
- Gate: a disabled bot or an expired trial does not dispatch a reply job.
- `FacebookMessengerChannel->sendReply` persists the assistant message and calls
  `FakeFacebookGraph::sendMessage` with the right recipient and page token.
- OAuth `callback` stores the `FacebookPage` (page id, token) and calls
  `subscribeApp`.
- OAuth `callback` failure (error query / no pages) redirects with a failure status.
- `ChannelResolver` returns `WebChatChannel` for `'web'` and
  `FacebookMessengerChannel` for `'facebook'`.
- Dashboard reflects real Facebook connection state.

## Out of Scope

- Multiple Facebook Pages per workspace (auto-pick first; schema allows one).
- Page picker UI for users managing several pages.
- Non-text message types (attachments, postbacks, quick replies).
- Human approval / handoff workflow beyond the existing `needs_human` status.
- Stripe billing.
