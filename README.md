# Merged Modules API

Laravel API for event discovery and ticket sales. It combines event, ticket-type, and reservation inventory with Stripe checkout, order management, refunds, and a transaction ledger. The old simulated `items` inventory and demo seed data are intentionally excluded.

## Technology

- PHP 8.3 or later and Laravel 13.
- Laravel Sanctum personal access tokens for API authentication.
- Stripe Checkout and Stripe webhooks for payment status, payment details, and refunds.
- Eloquent and migrations for persistence. SQLite is the default local database; MySQL, MariaDB, PostgreSQL, and SQL Server are configured as alternatives.
- Queued Laravel mailables for payment and refund confirmations. Resend is the default mail transport.
- Vite and Tailwind are included for asset builds; the main application surface is the JSON API.

## Setup

Install PHP dependencies:

```bash
composer install
```

On Windows PowerShell, copy `.env.example` to `.env`; on macOS/Linux, use `cp .env.example .env`. Then set the application key, prepare the database, and run migrations:

```bash
php artisan key:generate
php artisan migrate
```

The default connection is SQLite at `database/database.sqlite`. Create that file if it does not exist, or configure `DB_CONNECTION`, `DB_DATABASE`, and the appropriate connection values in `.env` for another database.

Configure the integrations below before testing real email or Stripe flows. Start the API and its background workers in separate terminals:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
```

The scheduler expires holds every minute. A local asset build is optional; when needed, run `npm install` and `npm run build`.

### Existing databases

Payment-method and Module-B ledger columns are part of the initial event/payment schema migration. If a database already ran an earlier version of that initial migration, running `php artisan migrate` will not add those columns. Back up the database, then use an explicit schema/data migration before deploying to a database that must retain data. For disposable local or test data only, `php artisan migrate:fresh` rebuilds all tables and **deletes all data**.

## Configuration

Set these values in `.env` as required for the environment:

| Variable | Purpose |
| --- | --- |
| `APP_URL` | Base URL used when the application generates links. |
| `FRONTEND_URL` | Optional client URL for password-reset links; falls back to `APP_URL`. |
| `DB_CONNECTION`, `DB_DATABASE` | Database driver and database name/path. Configure host, port, username, and password for server databases. |
| `QUEUE_CONNECTION` | Queue backend. The example uses the database queue. A worker must run to send queued confirmation emails. |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Outgoing email transport and sender. The example uses Resend. |
| `RESEND_API_KEY` | Required when using the Resend mail transport. |
| `STRIPE_KEY`, `STRIPE_SECRET` | Stripe publishable and secret keys. The backend Stripe client uses the secret key. |
| `STRIPE_WEBHOOK` | Stripe signing secret used to validate incoming webhook requests. |

The Stripe webhook endpoint is `POST /api/stripe/webhook`. Configure Stripe to send `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.succeeded`, `charge.updated`, `charge.refunded`, and `refund.updated` events to it.

## Authentication And Roles

Registration requires `name`, `email`, `password`, `password_confirmation`, and `role`. The accepted roles are `attendee` and `organizer`. Registration and login return a Sanctum bearer token. Send it as `Authorization: Bearer <token>` to authenticated endpoints.

Organizer actions require both the `organizer` role and verified email. Attendees can manage only their own holds, orders, and refunds. The hold token hash, password, and remember token are hidden from serialized model responses. Login, registration, password recovery, email verification, and verification-notification routes are rate-limited.

## API

All routes are under `/api`. Validation failures use Laravel's JSON validation response format. Monetary amounts named `*_cents` are integer US cents.

### Authentication and account

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| `POST` | `/register` | Public | Register an attendee or organizer; returns user and token. |
| `POST` | `/login` | Public | Authenticate and issue a token. |
| `POST` | `/logout` | Authenticated | Revoke all tokens for the current user. |
| `GET` | `/me` | Authenticated | Return the current user. |
| `POST` | `/forgot-password` | Public | Send a password-reset link. |
| `POST` | `/reset-password` | Public | Set a new password using the reset token. |
| `GET` | `/email/verify/{id}/{hash}` | Signed | Verify an email address. |
| `POST` | `/email/verification-notification` | Organizer | Resend the organizer verification message. |

### Event discovery and organizer management

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| `GET` | `/events` | Public | Browse published events; supports `name`, `town`, `date_from`, `date_to`, `min_price_cents`, and `max_price_cents`. Results are paginated. |
| `GET` | `/events/{event}` | Public for published events | Read a published event and its ticket types. Organizers may read their own unpublished events. |
| `GET`, `POST` | `/organizer/events` | Verified organizer | List owned events or create an event. |
| `GET`, `PATCH` | `/organizer/events/{event}` | Verified owner | Read or update an owned event. Event status is not changed through `PATCH`. |
| `POST` | `/organizer/events/{event}/cancel` | Verified owner | Cancel an event, release active holds, and request refunds for purchased lines. |
| `GET`, `POST` | `/organizer/events/{event}/ticket-types` | Verified owner | List or create ticket types. Creation accepts `name`, `base_price_cents`, `quantity`, and optional `discount`. |
| `PATCH`, `DELETE` | `/organizer/ticket-types/{ticketType}` | Verified owner | Update a ticket type or delete one with no hold/order history. |
| `GET` | `/organizer/events/{event}/inventory` | Verified owner | Return total, sold, held, and remaining inventory. |
| `GET` | `/organizer/sales_summary` | Verified organizer | Summarize order count, payments, refunds, and collected cents for owned events. |

### Holds, orders, checkout, and refunds

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| `POST` | `/ticket-types/{ticketType}/holds` | Attendee | Reserve a positive ticket quantity; returns a one-time release token. |
| `POST` | `/holds/{hold}/release` | Attendee | Release an owned active hold using its token. |
| `POST` | `/checkout` | Attendee | Create an order and Stripe Checkout session for `hold_ids`; requires an `Idempotency-Key` header. |
| `GET` | `/orders` | Attendee | List the current attendee's orders and ticket lines. |
| `GET` | `/order_items` | Attendee | List the current attendee's ticket lines. |
| `POST` | `/refund` | Attendee | Request a full refund for an owned order; body contains the order `id`. |
| `GET` | `/checkout/success` | Public | Stripe return endpoint; webhook delivery remains authoritative for payment status. |
| `GET` | `/checkout/cancel` | Public | Checkout cancel response; holds remain until released or expired. |
| `POST` | `/stripe/webhook` | Stripe signature | Validate and process Stripe webhook events. |

Checkout accepts one to twenty distinct hold IDs. Repeating a completed checkout request with the same user and idempotency key replays the saved response; a concurrent request with that key receives a conflict response.

## Domain And Persistence

The main schema is defined in `database/migrations`. The standard Laravel migrations additionally create users, sessions, password-reset tokens, cache, queue, and Sanctum token tables.

| Table | Responsibility |
| --- | --- |
| `users` | Attendee and organizer accounts; organizer accounts require verified email for organizer actions. |
| `events` | Organizer-owned events with draft, published, or cancelled status. |
| `ticket_types` | Event ticket price, discount percentage, and total quantity. Effective price is calculated and exposed in cents. |
| `holds` | Inventory reservations with owner, quantity, status, expiry, and a hash of the one-time release token. |
| `orders` | Checkout/payment state, total cents, Stripe session/payment-intent IDs, and normalized payment-method fields. |
| `order_items` | Immutable checkout snapshots of ticket type, quantity, unit price, subtotal, and associated hold. |
| `ledger_entries` | Idempotent payment/refund journal rows keyed by `reference_key`; `amount_cents` is canonical. `payment`, `refund`, and `adjustment` are decimal compatibility fields. |
| `refund_requests` | Scoped refund attempts, affected order lines, reason, Stripe refund ID, idempotency key, state, and failure details. |
| `idempotency_keys` | Per-user checkout request claims and replayable responses. |
| `stripe_webhook_events` | Stripe event deduplication, processing attempts, and retry state. |

Order payment-method columns include type, Stripe payment-method ID, brand, last four, and a curated JSON details object. The application does not persist full Stripe payment payloads or card numbers. Ticket/order values use cents as the source of truth; the legacy decimal ledger fields mirror payment and refund amounts in currency units.

## Main Workflows

### Ticket reservation and checkout

1. An attendee creates a ten-minute hold. `HoldService` locks the event and ticket type, checks active and confirmed inventory, and stores only a hash of the release token.
2. `CheckoutService` validates ownership and hold state, locks inventory in stable order, snapshots ticket prices into order lines, then creates the Stripe Checkout session.
3. Checkout is idempotent per attendee and key. Stripe session metadata links the session and PaymentIntent to the local order.
4. `checkout.session.completed` confirms payment only after validating the session amount, currency, attendee, event status, and all holds. It confirms holds and writes one payment ledger entry.
5. PaymentIntent/Charge events store payment-method details. Confirmation mail is queued after both the paid order and payment details are available.

Holds expire every minute through the scheduler. Checkout sessions expire after 30 minutes. If a successful payment arrives after a hold is no longer valid, the order enters refund processing rather than confirming unavailable tickets.

### Refunds and event cancellation

Attendee refunds are full-order requests. Event cancellation requests refunds for the order lines belonging to that event; each refund request is idempotent and is reconciled with Stripe webhooks. Successful refunds create negative `amount_cents` ledger entries, release only affected confirmed holds, update the order state, and queue a refund confirmation. The refund email shows the order's ticket lines; for a partial refund those lines describe the order, not necessarily only the refunded subset.

### Email and background work

`OrderPaid` and `OrderRefunded` events are handled by queued confirmation listeners. Run `php artisan queue:work` for email delivery. Run `php artisan schedule:work` in development or configure Laravel's scheduler in production so expired holds are transitioned to `expired`.

## Code Map

- `routes/api.php`: public, attendee, and organizer API route definitions.
- `app/Http/Controllers/Api`: request validation and HTTP response mapping.
- `app/Http/Middleware`: role authorization, organizer verification, idempotency, and JSON response formatting.
- `app/Services`: reservation, event/ticket management, checkout, Stripe integration, webhooks, and refunds.
- `app/Models`: Eloquent records and relationships.
- `app/Events`, `app/Listeners`, and `app/Mail`: queued payment/refund notifications.
- `database/migrations`: source schema; `database/factories` and `database/seeders` support tests and local development.
- `resources/views/emails`: HTML payment and refund confirmation templates.
- `tests/Feature/MergedModulesFlowTest.php`: end-to-end coverage for the merged business workflows.

## Testing And Useful Commands

Run the automated suite:

```bash
php artisan test
```

Inspect routes or migration state:

```bash
php artisan route:list --path=api
php artisan migrate:status
```

Format changed PHP files with Pint:

```bash
php vendor/bin/pint --format agent path/to/changed-file.php
```

Do not run `migrate:fresh` against a database with data that must be retained.
