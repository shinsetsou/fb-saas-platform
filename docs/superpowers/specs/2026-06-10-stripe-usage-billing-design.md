# Stripe Usage-Based Billing (Spec 2)

## Summary

Add paid subscriptions to the trial-first SaaS. Billing is **usage-based**: a
workspace pays a monthly **base fee** that includes a **quota** of AI replies,
then a **per-reply overage** beyond the quota. The billable unit is every
non-empty AI reply the bot sends, across **all channels** (web tester and
Facebook).

The master spec (`2026-06-02-facebook-page-calendar-saas-design.md`) deferred
Stripe explicitly: "Stripe billing can be added later without changing the core
workspace model." This spec honors that — the existing `Trial` model and
workspace tenancy are kept; billing is layered on top.

Trial-first conversion: signup still opens a 14-day `trialing` `Trial` with **no
card required**. While the trial is active the bot runs. At trial expiry the bot
stops (the gate) until the owner subscribes via Stripe Checkout. An active
subscription keeps the bot running indefinitely.

## Context and Existing Patterns

This spec reuses patterns already established in the codebase:

- **Per-workspace ownership.** The `Workspace` — not the `User` — owns billing
  and configuration (`hasOne` Trial/BotSetting/FacebookPage/GoogleAccount). The
  Cashier `Billable` trait goes on `Workspace`.
- **Gateway interface + Fake.** `FacebookGraph` + `HttpFacebookGraph` +
  `FakeFacebookGraph`, bound in a service provider, swapped in tests. Stripe
  meter reporting gets the same shape: `UsageMeter` + `StripeUsageMeter` +
  `FakeUsageMeter`.
- **Channel abstraction.** `ConversationChannel::sendReply` (implemented by
  `WebChatChannel` and `FacebookMessengerChannel`), resolved per conversation by
  `ChannelResolver`. Both channels already persist the assistant `Message`.
- **Gate.** `FacebookWebhookController` already gates dispatch on
  `botSetting.is_enabled` AND `trial->isActive()`. Billing extends the trial
  half of that condition.
- **Feature tests** use `RefreshDatabase`, exercise real HTTP routes, and bind a
  Fake gateway in the container.

## Package

Use **Laravel Cashier (Stripe)** — `laravel/cashier`. It provides the customer
model, subscription tables, Stripe Checkout, the billing portal, and a
signature-verified webhook controller, so none of that is hand-rolled.

## Data Model

### Cashier-managed tables

Cashier's published migrations add:

- Stripe columns on `workspaces`: `stripe_id`, `pm_type`, `pm_last_four`,
  `trial_ends_at`. (The app's own `trials` table remains the source of truth for
  the app trial gate; Cashier's `trial_ends_at` is unused for gating and left
  null.)
- `subscriptions` table (`stripe_id`, `stripe_status`, `stripe_price`, etc.).
- `subscription_items` table (per-price items, including the metered item).

`Workspace` uses the `Laravel\Cashier\Billable` trait. Because a Stripe customer
needs an email/name and the workspace has no email, override:

- `stripeEmail()` → the owner user's email (first `owner`-role user).
- `stripeName()` → the workspace name.

### `usage_records` table (local ledger — source of truth for billing audit)

| column | type | notes |
|--------|------|-------|
| `id` | bigint pk | |
| `workspace_id` | fk → workspaces | |
| `message_id` | fk → messages, **unique** | the assistant `Message` billed; uniqueness = idempotency |
| `channel` | string | `'web'` or `'facebook'` (denormalized for reporting) |
| `reported_at` | timestamp, nullable | when pushed to Stripe; null = pending/replayable |
| timestamps | | |

One row per billable reply. The unique `message_id` means a queue retry of
`ProcessIncomingMessage` cannot double-record or double-bill. The local ledger
is authoritative; Stripe meter events are derived from it, so a Stripe outage
never loses billing data (unreported rows can be replayed later).

### `UsageRecord` model

- `belongsTo(Workspace)`, `belongsTo(Message)`.
- `casts`: `reported_at => datetime`.

## Stripe Products and Prices

Configured in the Stripe dashboard, referenced by ID in config — **not** created
by the app:

- One **Product** ("AI Booking Bot").
- A subscription with two prices/items:
  1. **Base price** — flat recurring monthly amount (licensed/flat price).
  2. **Metered price** — bound to a Stripe **Meter** with event name
     `ai_replies`, using **tiered** pricing: tier 1 covers the included quota at
     $0, tier 2+ is the per-reply overage. This yields "base fee + included
     quota + overage" with a single metered item.

`config/services.php` `stripe` entry:

- `key` — `env('STRIPE_KEY')` (Cashier reads this).
- `secret` — `env('STRIPE_SECRET')` (Cashier reads this).
- `webhook_secret` — `env('STRIPE_WEBHOOK_SECRET')` (Cashier reads this).
- `price_base` — `env('STRIPE_PRICE_BASE')`.
- `price_metered` — `env('STRIPE_PRICE_METERED')`.
- `meter_event` — `env('STRIPE_METER_EVENT', 'ai_replies')`.

## Usage Recording

The billable moment is a non-empty assistant reply being sent.

- **`ConversationChannel::sendReply` return type changes** from `void` to
  `?Message`. It returns the persisted assistant `Message`, or `null` when it
  skips (empty text). `WebChatChannel` and `FacebookMessengerChannel` both
  updated to return the created message (or null on empty).
- **`ProcessIncomingMessage::handle`** records usage after the reply:
  ```php
  $message = $channels->for($conversation)->sendReply($conversation, $reply);
  if ($message !== null) {
      $recorder->record($message);
  }
  ```
- **`UsageRecorder` service** (`App\Services\Billing\UsageRecorder`):
  ```php
  public function record(Message $message): void
  {
      $conversation = $message->conversation;
      $workspace = $conversation->workspace;

      $record = UsageRecord::firstOrCreate(
          ['message_id' => $message->id],
          ['workspace_id' => $workspace->id, 'channel' => $conversation->channel],
      );

      if (! $record->wasRecentlyCreated) {
          return; // already counted (retry)
      }

      if ($workspace->subscribed('default')) {
          $this->meter->report($workspace);
          $record->update(['reported_at' => now()]);
      }
  }
  ```
  Trial-period replies are recorded locally (analytics, audit) but **not** pushed
  to Stripe — there is no customer/subscription yet. Reporting begins once the
  workspace is subscribed.

- **`UsageMeter` interface** (`App\Services\Billing\UsageMeter`):
  ```php
  interface UsageMeter
  {
      public function report(Workspace $workspace, int $count = 1): void;
  }
  ```
  - `StripeUsageMeter` — real; calls Cashier
    `$workspace->reportMeterEvent(config('services.stripe.meter_event'), $count)`.
  - `FakeUsageMeter` — records calls for tests.
  - Bound `UsageMeter => StripeUsageMeter` in `BotServiceProvider`.

Stripe reporting happens inline in the (already queued) job. A reporting failure
is caught and logged, leaving `reported_at` null so the row can be replayed; it
does not fail the reply.

## Gate

Add `Workspace::canUseBot(): bool`:

```php
public function canUseBot(): bool
{
    return $this->trial?->isActive() || $this->subscribed('default');
}
```

- `FacebookWebhookController` gate changes from
  `botSetting.is_enabled && trial.isActive()` to
  `botSetting.is_enabled && workspace.canUseBot()`.
- `BotEngine` still short-circuits on `is_enabled` (unchanged).
- The web **chat-tester** stays ungated (owner-only internal testing). It still
  records usage locally; that usage is pushed to Stripe only if the workspace is
  subscribed (per `UsageRecorder`).

## Subscribe and Manage Flow

`BillingController`, registered under the existing `auth`/`verified` group:

- **`checkout`** — resolve the workspace, then:
  ```php
  return $workspace
      ->newSubscription('default', [config('services.stripe.price_base')])
      ->meteredPrice(config('services.stripe.price_metered'))
      ->checkout([
          'success_url' => route('dashboard').'?status=subscribed',
          'cancel_url' => route('dashboard'),
      ]);
  ```
  Redirects to Stripe Checkout. No card is collected before this point.
- **`portal`** — `return $workspace->redirectToBillingPortal(route('dashboard'));`
  for managing/cancelling the subscription.

Cashier's webhook controller (route `stripe/webhook`) keeps `subscriptions` /
`subscription_items` in sync with Stripe (subscription created, updated,
cancelled, payment failed). `stripe/*` is added to the CSRF exception list in
`bootstrap/app.php` (alongside `webhooks/facebook`); the Cashier route is
signature-verified.

## Dashboard and Config

- `DashboardController` additionally provides:
  - `billingStatus` — `'subscribed'` if `subscribed('default')`, else
    `'trialing'` if `trial->isActive()`, else `'lapsed'`.
  - `repliesThisPeriod` — count of `usage_records` for the workspace in the
    current calendar month (simple month window for v1).
- Dashboard view: a billing block showing the status badge and replies-this-period,
  with a **Subscribe** button (→ `billing.checkout`) when not subscribed, or a
  **Manage billing** button (→ `billing.portal`) when subscribed. Handles a new
  `subscribed` status flash.
- `config/services.php` gains the `stripe` entry above; `.env.example` gains the
  `STRIPE_*` keys (left blank).

## Error Handling

- Cashier verifies the Stripe webhook signature; invalid webhooks are rejected by
  Cashier's controller.
- Usage is recorded locally first and is idempotent on `message_id`, so retries
  and partial failures never double-bill.
- A Stripe meter-report failure is caught and logged; the local row stays
  unreported (`reported_at` null) for replay and does not break the reply path.
- Checkout/portal failures surface as Cashier exceptions; the buttons are only
  shown to authenticated owners.

## Testing

Feature tests using `RefreshDatabase`, real HTTP routes, with `FakeUsageMeter`
bound in the container. Subscription state is simulated by inserting a
`subscriptions` row with `stripe_status = 'active'` (no live Stripe calls).

- `Workspace::canUseBot()` matrix: trial active → true; trial expired + no
  subscription → false; trial expired + active subscription → true.
- `ConversationChannel::sendReply` returns the persisted `Message` for both
  `WebChatChannel` and `FacebookMessengerChannel`; returns null on empty text.
- `UsageRecorder::record` creates a `usage_records` row; a second call for the
  same `message_id` is a no-op (idempotency).
- `UsageRecorder::record` pushes to `FakeUsageMeter` and sets `reported_at` only
  when the workspace is subscribed; for a trial-only workspace it records locally
  and does **not** push.
- Webhook gate: a lapsed workspace (trial expired, no subscription) does not
  dispatch `ProcessIncomingMessage`; a subscribed workspace with an expired trial
  does dispatch.
- `ProcessIncomingMessage` records exactly one `usage_records` row per non-empty
  reply (and none when the reply is empty).
- Billing routes (`billing.checkout`, `billing.portal`) require auth.
- Dashboard reflects billing status (trialing / subscribed / lapsed) and shows
  the correct Subscribe vs Manage button.

## Out of Scope

- Multiple plans/tiers (single base+metered plan only).
- Proration UI, coupons, tax configuration (handled in Stripe dashboard if
  needed).
- Dunning/email flows beyond Stripe's own and Cashier's default webhook handling.
- Per-day usage aggregation / scheduled batch reporting (usage is reported inline
  per reply; the local ledger supports adding batch replay later).
- Replaying unreported `usage_records` to Stripe (the schema supports it via
  `reported_at`; the replay job itself is a later addition).
- Hard enforcement of the included quota in-app (Stripe tiered pricing handles
  overage billing; the app does not block replies at the quota boundary).
