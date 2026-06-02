# Facebook Page Calendar SaaS Design

## Summary

Build a Laravel-based multi-client SaaS platform that lets businesses sign up, connect a Facebook Page, connect a Google Calendar, and run a full-autopilot AI assistant for Facebook Page messages. The assistant answers configured business questions and books appointments directly on the connected Google Calendar.

The first version is self-serve and trial-first. It tracks trial status but does not include paid subscriptions. Stripe billing can be added later without changing the core workspace model.

## Product Scope

Each business gets one workspace. Each workspace supports:

- One connected Facebook Page.
- One connected Google Calendar.
- One bot configuration profile.
- Message and conversation history.
- Booking records and Google Calendar event references.
- AI decision and automation audit logs.
- Trial state and bot enablement state.

Version 1 does not include:

- Multiple staff calendars.
- Stripe subscriptions.
- Human approval workflow.
- Multiple Facebook Pages per business.
- CRM pipelines or broad customer management features.

## Target User

The first product serves businesses that want a general Facebook Page inbox assistant, with appointment booking as the flagship action. The product is not limited to one business category, but booking behavior is optimized for appointment-style interactions.

## Architecture

The app uses Laravel as a single full-stack SaaS codebase with authentication, workspace ownership, OAuth integrations, queues, migrations, scheduled tasks, and dashboard views.

Core components:

- Web app: dashboard for signup, onboarding, settings, logs, bookings, and trial status.
- Auth and workspace layer: users own or manage one business workspace.
- Facebook integration: OAuth connection, webhook verification, incoming message receiver, reply sender.
- Google Calendar integration: OAuth connection, availability lookup, appointment creation.
- AI orchestration service: classifies messages, extracts booking intent and details, chooses reply or booking actions.
- Queue workers: process Facebook messages asynchronously so webhook responses remain fast.
- Audit/logging layer: records incoming messages, AI decisions, outbound replies, calendar actions, and errors.

## Data Flow

1. A customer sends a message to a connected Facebook Page.
2. Facebook calls the Laravel webhook.
3. Laravel verifies the webhook request and maps the Page to a workspace.
4. Laravel stores the incoming message and ignores duplicate webhook events.
5. A queued job processes the message through the AI orchestration service.
6. If required booking details are missing, the bot sends a follow-up question.
7. If required details are present, the app checks Google Calendar availability.
8. If the slot is available, the app creates a Google Calendar event and stores the event ID.
9. The bot replies through Facebook Messenger with either an answer, a follow-up question, alternatives, or a booking confirmation.
10. The business can view the conversation, AI decision, outbound reply, booking, and errors in the dashboard.

## Booking Behavior

The bot runs on full autopilot but should be conservative around calendar changes.

The bot collects:

- Customer name.
- Requested service or appointment reason.
- Preferred date.
- Preferred time.
- Contact detail when Facebook identity is not enough.
- Optional notes.

The business configures:

- Business name and description.
- Services or appointment types.
- Default appointment duration.
- Timezone.
- Booking hours.
- Buffer time between appointments.
- Cancellation and rescheduling instructions.
- Bot tone and instructions.

The app must not create a calendar event until required details are present and the requested slot is available. If the requested slot is unavailable, the bot proposes alternatives. After a successful booking, the bot sends a confirmation and stores the Google Calendar event ID.

## Autopilot Safety

The assistant should avoid unsupported commitments. If a customer asks for something outside configured business information or booking rules, the bot replies politely that it cannot confirm that request and redirects the customer toward supported topics such as services, hours, availability, or appointment booking.

The workspace has a bot enable/disable switch. When disabled or when the trial expires, the system stops automated replies while keeping the dashboard accessible.

## Data Model

Initial tables:

- `users`: SaaS users who sign in to the platform.
- `workspaces`: business tenant records.
- `workspace_users`: relationship between users and workspaces.
- `connected_accounts`: encrypted OAuth tokens and provider metadata for Facebook and Google.
- `bot_settings`: business profile, tone, booking rules, timezone, and bot status.
- `conversations`: Facebook customer conversation records scoped to a workspace.
- `messages`: inbound and outbound message records.
- `bookings`: appointment records with Google Calendar event IDs.
- `ai_decision_logs`: AI classification, extracted fields, action, confidence or reason summary, and resulting output.
- `webhook_events`: provider event IDs and processing state for idempotency.
- `trials`: trial start, trial end, and trial status.

## Error Handling

Facebook webhook verification and signatures must be checked before processing. OAuth tokens for Facebook and Google must be encrypted at rest.

Webhook processing must be idempotent so duplicate Facebook deliveries do not create duplicate replies or bookings. Calendar creation must perform an availability check immediately before writing the event.

Queued jobs can retry failed message processing, but booking creation must avoid duplicate calendar events. Errors should be visible in the dashboard with enough context to diagnose failed automations.

## Dashboard

The first dashboard should be quiet and work-focused. After login, the business sees:

- Facebook connection status.
- Google Calendar connection status.
- Bot enabled or disabled status.
- Trial status.
- Recent conversations.
- Recent bookings.
- Recent errors or failed automations.

Dashboard sections:

- Onboarding checklist.
- Account connections.
- Bot settings.
- Message history.
- Booking history.
- Automation logs.
- Trial status.

## Testing

Test coverage should focus on integration boundaries and automation risks:

- Facebook webhook verification.
- Incoming message parsing.
- Workspace and tenant isolation.
- Duplicate webhook delivery handling.
- Booking detail extraction flow.
- Missing booking details follow-up.
- Google Calendar availability checks.
- Calendar event creation.
- Trial expiry disabling automated replies.
- Bot enable/disable switch behavior.

## Implementation Notes

Use Laravel queues for asynchronous message handling. Keep provider-specific logic in service classes so Facebook, Google, and AI behavior can be tested independently.

The first implementation should support one calendar and one Facebook Page per workspace, while keeping table names and relationships flexible enough to support multiple connected accounts later.
