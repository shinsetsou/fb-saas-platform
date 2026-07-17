# Improvements

A prioritized list of issues, gaps, and recommended next work. Each item
cites the file and line where useful, and a few carry suggested fixes.

P0 — bugs and security. These will hurt users in production.
P1 — UX gaps that block real adoption.
P2 — test coverage gaps.
P3 — model / code quality.
P4 — config, ops, and dev experience.

---

## P0 — bugs and security

### 1. `BotSetting` is missing a `workspace()` relationship

`app/Models/BotSetting.php` declares no `belongsTo(Workspace::class)`. Every
other tenant model (`FacebookPage`, `GoogleAccount`, `Conversation`,
`Appointment`, `UsageRecord`) has one. `$botSetting->workspace` falls through
to Eloquent's dynamic property handler and returns `null` rather than the
related workspace.

**Fixed.** Added `public function workspace(): BelongsTo { return
$this->belongsTo(Workspace::class); }` to `app/Models/BotSetting.php`.

### 2. `BotSettingsController::update` silently drops `booking_hours`

`bot_settings.booking_hours` is a JSON column that `SlotGenerator` consumes to
produce candidate slots. The web form
(`resources/views/bot-settings/edit.blade.php`) had no field for it, the
controller's `$request->validate([...])` did not include it, and the seeded
default is `null`. Net effect: every workspace's bot shipped with no working
availability until someone edited the DB by hand.

**Fixed.** Added:

- `app/Support/BookingHours.php` — `normalize()` + `validate()` helpers for
  the canonical 7-day map shape. `normalize()` collapses an empty input to
  `null` (treated as "closed every day" by the slot generator).
- `app/Http/Controllers/BotSettingsController.php` — validates the array
  shape and surfaces `BookingHours::validate()` errors as a single
  `booking_hours` validation error with one line per problem.
- `resources/views/bot-settings/edit.blade.php` — per-weekday table with
  start/end `<input type="time">` fields, "+ Add range" / "− Remove" handled
  in a small Alpine component.
- `resources/js/app.js` — `Alpine.data('bookingHours', …)` initializer.
- `tests/Unit/BookingHoursTest.php` — 7 unit tests pinning the shape and
  the validation rules.
- `tests/Feature/BotSettingsTest.php` — extended with an "off + clear
  hours" test and an "overlapping ranges rejected" test.

### 3. Tailwind v3 vs v4 mismatch in `package.json`

`package.json` shipped both `tailwindcss ^3.1.0` and `@tailwindcss/vite
^4.0.0`, which are mutually exclusive. The runtime config
(`tailwind.config.js`, `postcss.config.js`, `resources/css/app.css` with
`@tailwind base/components/utilities`) was Tailwind v3.

**Fixed.** Removed `@tailwindcss/vite` from `package.json`; ran
`npm install`. The v3 build runs cleanly.

### 4. `CLAUDE.md` was factually wrong about `facebookConnected` / `googleConnected`

The previous `CLAUDE.md` claimed those dashboard flags were hardcoded
`false`. That is no longer true — `DashboardController::__invoke` reads them
from `FacebookPage` and `GoogleAccount` rows. The rewrite of `CLAUDE.md` in
the docs commit fixed the drift.

### 5. CSRF exemption for `stripe/*` is missing

`bootstrap/app.php` only exempts `webhooks/facebook`. Cashier's webhook
controller handles signature verification itself, but its routes live under
`stripe/*` and would be CSRF-blocked if any were registered.

**Fixed.** Added `'stripe/*'` to the CSRF exception list in
`bootstrap/app.php`. No Stripe webhook is registered today, but the
exemption is now in place for when one is added.

### 6. `FacebookController::callback` auto-picks the first page and overwrites

Lines 34–52 take the first page from the user's `pages` listing, subscribe
the app, and `updateOrCreate` the `FacebookPage` keyed on `workspace_id`. If
the workspace already has a `FacebookPage` and the user reconnects with a
*different* page, the new one wins with no warning. Likely desired (single
Page per workspace) but worth confirming in product. Old `webhook_events`
rows from the prior page id remain orphaned but harmless.

**Not changed.** Single Page per workspace is a deliberate product
constraint, not a bug. If/when multi-Page support is added, revisit.

### 7. `WebhookEvent.processed_at` is never stamped

`FacebookWebhookController::receive` inserted a `WebhookEvent` to dedup on
`event_id` but never set `processed_at`. The migration and model both
declare the column, so the data shape implies "seen vs. successfully
processed" — but the field was always null.

**Fixed.** The controller now stamps `processed_at` after the dedup check,
both when it dispatches the job and when it skips dispatch (bot disabled
or trial expired). The column is now honest: "non-null = we received and
acted on this message." Dedup is still anchored on `event_id`
uniqueness. `tests/Feature/FacebookWebhookTest.php` was extended to assert
`processed_at` is stamped in both the dispatch and the skip paths.

---

## P1 — UX gaps

### 8. No nav link to bot-settings, chat-tester, or billing

`resources/views/layouts/navigation.blade.php` shows only **Dashboard** plus
the user dropdown. The product features (bot settings, chat tester, billing)
are reachable only by URL. The first-time user has no path to them except
via the dashboard's per-section buttons.

**Fix.** Add a top-level nav: Dashboard, Bot Settings, Chat Tester, Billing.
Show a small badge for "not configured" items.

### 9. `welcome.blade.php` is the stock Laravel marketing page

No product copy, no signup CTA above the fold. First impression for a
visitor is "default Laravel app." This is fine while the product is
self-serve trial-first, but the moment marketing lands here, it needs
replacement.

### 10. Dashboard "Recent conversations" and "Recent bookings" cards are placeholders

Both render a single hardcoded paragraph: *"will appear here after the
Page/Calendar integration is connected."* The data is already available
through `$workspace->conversations()->latest()->limit(5)` and
`$workspace->appointments()->latest()->limit(5)`.

**Fix.** Render actual lists with an empty state when the lists are empty
("No conversations yet — connect a Facebook Page to get started").

### 11. No generic flash/status banner for password updates or generic errors

`bot-settings` and `chat-tester` render specific `status` flashes. The
dashboard banner only handles a fixed list of status strings — anything
else falls through to `{{ session('status') }}` which renders raw. Mostly
OK, but a generic Laravel validation error bag is not displayed on most
views.

### 12. No "Connect Calendar" / "Connect Page" onboarding flow beyond the dashboard

A first-time workspace owner has no guided path. A simple onboarding
checklist ("1. Set your business name. 2. Connect Google Calendar. 3.
Connect Facebook Page. 4. Enable the bot.") would help.

### 13. `chat-tester` has no rate limit

A user could spam the bot. Compare to `verification.send` at
`routes/auth.php:47` which uses `throttle:6,1`. Apply the same to
`POST /chat-tester` (e.g. `throttle:60,1`).

### 14. `welcome.blade.php` is reached at `/` and shows stock Laravel content

OK while in alpha but should be replaced with a product page before
external traffic.

---

## P2 — test coverage gaps

### 15. No test for `repliesThisPeriod` count in `DashboardController`

Insert a `usage_records` row in the test and assert the dashboard shows `1`.
The `where('created_at', '>=', now()->startOfMonth())->count()` query is the
only place this month-window logic lives.

### 16. No test for `BotSettingsController::update` "off" path

The `is_enabled` checkbox is unchecked → persists as `false`. Spec/test
only covers the on-state.

### 17. No test for `BotSettingsController::update` validation errors

E.g. duration=10, bad timezone, empty `business_name`, duration as a
string. Validation rules are inline in the controller and untested.

### 18. No test for `ChatTesterController` participant isolation between users

Two users posting to `/chat-tester` should not see each other's messages.
The current `participantId = 'tester-'.$user->id` ensures this — but no
test pins it.

### 19. No test for `CreateWorkspaceForUser` slug uniquification

The `while (Workspace::where('slug', $candidate)->exists())` loop is
untested. Register two users with the same name and assert the second
gets slug `-2`.

### 20. No test for `FacebookController::callback` "page has no access_token"

`HttpFacebookGraph::pages()` filters these out via `array_filter`; the
controller returns early. No test pins the "no usable pages" flash.

### 21. No test for `GoogleClientFactory` token refresh path

The whole class has zero test coverage. The spec defers its network path
testing, but a unit test against a fake `Client` would catch the
`now()->diffInSeconds($account->expires_at, false)` call which returns
**negative** seconds when `expires_at` is in the past.

### 22. No test for `BotEngine` tool-loop guard

The fake client has a fallback response after the scripted list, so the
loop converges at 2 iterations. A test that scripts 8 tool uses and asserts
the engine stops (no more dispatches) would lock in the `MAX_ITERATIONS = 8`
cap.

### 23. No test for `BotEngine` exception path

The try/catch at lines 92–97 catches `Throwable`, flips the conversation
to `needs_human`, and returns a friendly fallback string. No test feeds
a fake `ClaudeClient` that throws.

### 24. No test for `BotEngine` `cancel_appointment` or `reschedule_appointment` tool paths

`ToolRegistryTest` only covers `book_appointment`,
`request_human_handoff`, plus the definitions shape.

### 25. No end-to-end test for `WebhookEvent` dedup on the queue-worker path

Existing tests fake the queue. Once `ProcessIncomingMessage` actually runs
on the queue, the `WebhookEvent` is already written before dispatch — a
real end-to-end test would catch any race or ordering bug.

### 26. No test for `WebhookEvent` and `Subscription` / `SubscriptionItem` factories

These are internal ledgers; lightweight factories (or at least
`HasFactory` declarations) would help future tests.

### 27. No test for `WebChatChannel::sendReply` with `is_enabled=false` integration via the `ChatTester` route

Covered indirectly in
`ProcessIncomingMessageUsageTest::test_disabled_bot_empty_reply_records_no_usage`
but not at the controller level.

### 28. No test for the Stripe webhook handler

If/when `stripe/*` is added to the route list (see P0 #5), there is no
test that the handler exists, signature-verifies, and updates local
subscription state.

---

## P3 — model / code quality

### 29. `Workspace::stripeEmail()` assumes exactly one `owner`

Today, every workspace is single-user, so this returns the right thing. With
multi-user support coming, this needs to be the *current user's* email, not
any owner's.

### 30. `User::ownedWorkspace()` returns "the first owner"

Not the current user's owner workspace. The helper would silently pick the
wrong workspace if a user were attached to multiple. Controllers don't use
this helper today — they call `$request->user()->workspaces()->firstOrFail()`,
which is even more permissive (not even owner-filtered). That's the
actual "first workspace" gotcha.

### 31. `WorkspaceUser` is a degenerate Eloquent pivot

The model exists only so `$user->workspaces()->wherePivot(...)` works. Its
name reads as a domain concept. Consider either deleting it and using
`belongsToMany` directly, or renaming it to clarify it's a pivot.

### 32. `FacebookController::callback` uses `$request->user()->workspaces()->firstOrFail()`

If a user ever belongs to two workspaces, the connect targets whichever
comes first. Same fix as #30 — introduce a current-workspace concept.

### 33. No "current workspace" concept

Every controller that needs a workspace calls
`$request->user()->workspaces()->firstOrFail()`. With a single-user tenant
this is fine. With multi-tenant, behavior is undefined. The plan calls for
a session-stored `current_workspace_id` plus middleware that resolves it.

### 34. Dashboard 5-column grid collapses poorly on mobile

`md:grid-cols-5` with no smaller breakpoint means mobile is a single tall
column. Accessibility/UX could be improved (stacked on small screens, or
reorganized into 2- or 3-column on `sm`).

### 35. `ChatTesterController` uses `dispatchSync`

This blocks the HTTP request. For a tester owned by the same user, it's
fine, but the spec said "queued processing so the same engine drops behind a
webhook later with no rework." The tester deliberately bypasses the queue,
so retry/failed_jobs paths are not exercised here. Consider a feature flag
or a `?sync=1` query param so CI can occasionally run the real queue path.

### 36. `BotEngine` persists a `Message` for every tool call inside the loop

The persisted message count grows linearly with iterations. There is no
test or documentation of this storage pattern. A user with a long-running
convo accumulates `tool` rows. Probably fine in practice; document it.

### 37. `WebhookEvent` and `WorkspaceUser` don't declare `HasFactory`

`WebhookEvent` is fine (internal ledger), but `WorkspaceUser` would benefit
from one for future tests.

### 38. `SlotGenerator` assumes `booking_hours` is a map of weekday → array of `{start, end}` strings

The shape is documented only in
`docs/superpowers/plans/2026-06-09-conversation-engine-1a.md`. The DB
column has no check constraint. Add a migration-level JSON schema, or a
`BotSetting::getBookingHours(): array` accessor that validates shape on
read.

### 39. No policy classes

`BotSetting`, `FacebookPage`, `GoogleAccount`, `Appointment`, and
`Conversation` have no `Policy`. Authorization today is implicit in the
"first workspace" query. With multi-tenant, every controller needs a
policy that asserts the user belongs to the workspace owning the row.

---

## P4 — config, ops, and dev experience

### 40. `composer test` clears config cache but not view cache

If a developer tweaks a Blade view, the cached compiled view can mask the
change during tests. Add `@php artisan view:clear --ansi` to the `test`
script in `composer.json`.

### 41. `README.md` was the stock Laravel boilerplate

This commit replaces it with a real onboarding doc, but it can drift again
easily. Consider checking it on every release.

### 42. `.env.example` lists `FACEBOOK_GRAPH_VERSION=v25.0` but `config/services.php` and `HttpFacebookGraph` default to `v21.0`

Pick one and align both. Today's setup will route to a graph version that
doesn't exist on some calls.

### 43. `ANTHROPIC_MODEL=claude-opus-4-8` in `.env.example`

That model name does not match any current Anthropic model family. The SDK
call also uses `'thinking' => ['type' => 'adaptive']` and `'output_config' =>
['effort' => 'high']`, both of which require very recent model families.
The bot will fail at runtime in any sandbox where this is the only model
available. Validate with a smoke test that hits the API.

### 44. No CI config

Tests are runnable locally (`composer test`) but there is no
`.github/workflows` or other automation. Worth adding once the test suite
stabilizes.

### 45. Tailwind v3 `content` glob in `tailwind.config.js` doesn't include `resources/js/**/*.js`

Only Blade. Alpine classes added from JS would be purged. Low priority
since all styling happens in Blade, but a "Alpine `x-cloak` + dynamic
class" would break.

### 46. `google/apiclient` is a heavy dependency

Pulls in Guzzle, PSR-7, etc. Worth documenting in `CLAUDE.md`'s stack
section and the README's "What you get" — already done in the rewrite.

### 47. No scheduled tasks

`routes/console.php` is the only console entry. The spec calls out a
"replay unreported `usage_records`" job as out-of-scope, but if/when it's
added it will need a scheduler (`Schedule::command(...)`).

### 48. Queue is `database` in `.env` but `sync` in `phpunit.xml`

Correct (tests should be sync). `composer dev` does include
`php artisan queue:work` — confirm this is still the case in
`composer.json` after any updates.

### 49. `seeders/DatabaseSeeder.php` creates `test@example.com` with password "password"

Stock Breeze default. A fresh `php artisan db:seed` on a production-like
env will create a real account. Gate the seeder behind `app->environment()`
checks or document the risk.

### 50. No `lang/` custom strings

Relies entirely on Breeze defaults. Fine for v1; flag for v2 when the
product is multi-language.

---

## Suggested first PR (in order)

A concrete starting point that resolves the highest-leverage P0 items without
introducing a new feature:

1. **P0 #1**: Add `BotSetting::workspace()` relationship.
2. **P0 #2**: Add the `booking_hours` editor to the bot-settings form, the
   validation, and the controller persistence. This is the difference
   between "demo" and "product."
3. **P0 #3**: Remove `@tailwindcss/vite` from `package.json`.
4. **P1 #8**: Add top-nav links to Bot Settings, Chat Tester, Billing.
5. **P1 #10**: Wire real "Recent conversations" and "Recent bookings" cards
   on the dashboard.
6. **P2 #15 + #16 + #19**: Add three targeted tests (`repliesThisPeriod`,
   `is_enabled=false` path, slug uniquification).

That's roughly a day of work and removes every blocker to a real user
onboarding a real Google Calendar and Facebook Page.
