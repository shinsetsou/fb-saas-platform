# fb-saas

A micro-SaaS where business owners connect a Facebook Page and a Google Calendar to
an AI bot that handles Messenger-driven appointment booking. End-to-end: a customer
DMs a connected Facebook Page, the bot checks Google Calendar availability, books,
reschedules, or cancels via tools, and replies through Messenger. Usage is metered
to Stripe via Laravel Cashier.

Built on Laravel 12, Breeze (Blade + Alpine), Tailwind v3, SQLite (dev/test), and
Laravel Socialite for OAuth.

## Status

Complete through sub-projects 1a (conversation engine), 1b (Google Calendar booking),
1c (Facebook Messenger), and the Stripe usage-billing flow. The bot, integrations,
and billing are all wired up end-to-end and tested. See
[`docs/IMPROVEMENTS.md`](docs/IMPROVEMENTS.md) for known issues and recommended
next work.

## Quick start

### Option A: Docker (recommended for cross-platform dev)

A complete dev stack is included: PHP-FPM, Caddy, queue worker, scheduler, and
MySQL 8. One command boots it.

```bash
cp .env.docker .env        # then edit .env to add real API keys
docker compose up --build
# → http://localhost:8080
```

What's in the stack:

| Service     | Image                | Port | Role                                            |
| ----------- | -------------------- | ---- | ----------------------------------------------- |
| `app`       | `php:8.2-fpm-alpine` | 9000 | PHP-FPM, source bind-mounted                    |
| `webserver` | `caddy:2-alpine`     | 8080 | HTTP front-end, FastCGI to `app`                |
| `queue`     | (same as `app`)      | —    | `php artisan queue:work`                        |
| `scheduler` | (same as `app`)      | —    | `php artisan schedule:work`                     |
| `mysql`     | `mysql:8.0`          | 3306 | Database (named volume `mysql_data`)            |
| `node`      | `node:20-alpine`     | 5173 | Vite dev server — `compose --profile dev up node` |

Common commands:

```bash
docker compose exec app php artisan tinker           # open a REPL
docker compose exec app php artisan test             # run the full suite
docker compose exec app php artisan migrate:fresh --seed
docker compose logs -f queue                         # tail the queue worker
docker compose down -v                               # stop and wipe the DB
```

The `app` image installs `pdo_mysql`, `intl`, `zip`, `pcntl`, `opcache`, and
`redis`. Tests run against SQLite-in-memory (as in `phpunit.xml`), so the
test suite works without MySQL being reachable from the test process.

### Option B: local PHP

```bash
git clone <repo>
cd fb-saas

composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate

composer dev      # runs server + queue worker + log tailer (pail) + vite
```

Then visit `http://localhost:8000`, register an account, and you'll land on the
dashboard. To exercise the bot end-to-end without Facebook, open **Chat Tester**
(`/chat-tester`) and start a conversation. The test path uses
`FakeBookingService` until you connect a real Google Calendar.

## What you get

- **Multi-tenant workspaces** with a 14-day trial seeded on signup.
- **Bot configuration** (business name, hours, duration, buffer, tone, cancellation
  copy) at `/bot-settings`.
- **A web chat tester** at `/chat-tester` that exercises the same engine the
  Facebook channel uses.
- **Facebook Page OAuth + Messenger webhook** wired through `webhooks/facebook`
  (GET verify, POST receive). Signature verification and `mid` dedup are live.
- **Google Calendar OAuth + booking service** with token refresh, free/busy
  lookup, event insert/update/delete.
- **Stripe subscription** (Cashier): one base price + one metered usage price for
  AI replies. Trials skip the Stripe push; the local `usage_records` ledger is
  always written first.

## Required environment

These need real values before the integrations work in production. See
`.env.example` for the full list.

| Var | Used for |
| --- | --- |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` | Bot engine → Claude |
| `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `FACEBOOK_REDIRECT_URI`, `FACEBOOK_WEBHOOK_VERIFY_TOKEN` | Facebook Page OAuth + webhook verification |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Google Calendar OAuth |
| `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_BASE`, `STRIPE_PRICE_METERED`, `STRIPE_METER_EVENT` | Cashier subscription + metered usage |

For local development the Anthropic, Facebook, Google, and Stripe calls can all
be replaced with the fakes in `tests/Support/` by running under
`APP_ENV=testing`.

## Local URLs

When running via Docker Compose, the app is served at **`http://localhost:8080`**.
For the local-PHP path, it's `http://localhost:8000`. Paths are the same in both.

| Path | Auth | Purpose |
| --- | --- | --- |
| `/` | public | Landing page (stock welcome) |
| `/register`, `/login` | guest | Breeze auth |
| `/dashboard` | auth + verified | Workspace overview, integration status, billing |
| `/bot-settings` | auth + verified | Edit `BotSetting` |
| `/chat-tester` | auth + verified | Talk to the bot from the browser |
| `/facebook/connect` `/callback` `/disconnect` | auth + verified | Facebook Page OAuth |
| `/google/connect` `/callback` `/disconnect` | auth + verified | Google Calendar OAuth |
| `/billing/checkout` `/billing/portal` | auth + verified | Cashier checkout / billing portal |
| `/webhooks/facebook` (GET/POST) | public, signature-verified | Facebook webhook verify + receive |

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — system overview for engineers
  new to the repo (tenancy, bot engine, booking, inbound flow, billing).
- [`docs/IMPROVEMENTS.md`](docs/IMPROVEMENTS.md) — known issues and prioritized
  next work (P0 → P4).
- [`docs/superpowers/specs/`](docs/superpowers/specs/) — design history for each
  sub-project.
- [`docs/superpowers/plans/`](docs/superpowers/plans/) — TDD plans that drove
  each sub-project to completion.
- [`CLAUDE.md`](CLAUDE.md) — guidance for AI coding assistants (essentially a
  short architecture brief).

## Testing

```bash
composer test                       # full suite (clears config cache first)
php artisan test                    # same, no cache clear
php artisan test --filter BotEngine # single class
```

Feature tests exercise real HTTP routes (e.g. `$this->post('/register', ...)`)
against an in-memory SQLite database with `RefreshDatabase`. Third-party calls
are stubbed via fakes in `tests/Support/` that are bound through
`BotServiceProvider::testing()` when `APP_ENV=testing`.

## Conventions

- Multi-step domain logic that must stay atomic across a flow lives in an
  invokable Action class under `app/Actions/`. The workspace creation on signup
  (`CreateWorkspaceForUser`) is the canonical example.
- Anything that talks to a third party is a `*Graph` / `*Calendar` / `*Client`
  interface in `app/Services/` with a real HTTP implementation and a
  `tests/Support/Fake*.php`. Tests inject the fake; production binds the HTTP
  implementation in `BotServiceProvider`.
- Feature tests use real HTTP routes. They do not call controllers directly.
- Server-rendered Blade with Tailwind v3 and Alpine.js. No SPA framework.
