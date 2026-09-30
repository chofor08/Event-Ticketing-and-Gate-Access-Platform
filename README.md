# Merged Modules

Laravel application with a same-origin Blade/Vite frontend and JSON API for event discovery and ticket sales. It combines event, ticket-type, and reservation inventory with Stripe checkout, order management, refunds, and a transaction ledger. The old simulated `items` inventory and demo seed data are intentionally excluded.

## Scope By Module

The application combines three modules in one Laravel app, one identity system, one API, and one canonical database. The module names below describe functional ownership; users experience them as one ticketing and gate-operations product.

### Module A: Events And Ticket Inventory

- Verified organizers create and manage their own events. Events begin as `draft`, `published`, or `cancelled`; a draft is published through a dedicated action, and cancellation is terminal.
- Public discovery lists published events, including past events, ordered by event date. Search filters cover event name, town, date range, and whole-XAF ticket prices. Public event details expose ticket types only for published events; organizers can inspect their own unpublished events.
- Organizers create, update, and inspect ticket types for their events. Inventory reports total, sold, held, and remaining quantities. Quantities cannot be reduced below existing commitments, and ticket types with hold/order history cannot be deleted.
- Attendees reserve tickets only for published future events. Holds are owner-scoped, expire after 10 minutes, reserve inventory under a database lock, and return a one-time release token whose hash is stored. Attendees may release their own eligible holds; manual hold confirmation is not exposed.
- Module A is the sole source of event, ticket-type, and reservation inventory. Module B's simulated generic `items` catalog and demo data are excluded.

### Module B: Accounts, Checkout, Payments, And Refunds

- Public registration creates only `attendee` or `organizer` accounts. Organizers must verify email before organizer operations; attendees do not need verification to reserve or buy tickets. Sanctum bearer tokens expire after seven days, and logout revokes the account's tokens.
- Account flows include rate-limited login, password reset, email verification, and verification-email resend. Reset links open the same-origin Blade page and expire after 60 minutes.
- Checkout accepts one to twenty active holds belonging to the authenticated attendee, including holds from multiple events. It snapshots XAF prices into order lines, creates a Stripe Checkout session that expires after 30 minutes, and requires an `Idempotency-Key`. Completed requests replay their saved response; transient Stripe connectivity errors return `503` while preserving the pending order and holds for retry with the same key.
- Stripe webhooks, not browser return pages, determine payment state. Signed webhook processing is idempotent, validates the order/session/amount/currency and holds, records payment details and ledger entries, and queues transaction confirmation emails. A payment for holds that are no longer valid is refunded rather than used to confirm unavailable inventory.
- Attendees can view their own orders and ticket lines and request a refund for the remaining refundable balance of their own paid order. Event cancellation releases active holds and refunds only the affected event's paid lines in a mixed-event order. Refund requests and Stripe retries are recorded idempotently; refund emails report actual refunded amounts.
- Organizer sales summaries are scoped to events they own. For a mixed-organizer order, the full order payment and refund totals are attributed to each organizer represented in that order.
- All prices, order totals, Stripe line amounts, refunds, and ledger entries use whole integer XAF. No USD conversion, cent scaling, or fractional XAF amount is used.

### Module C: Tickets, Invitations, And Gate Admission

- A successful settlement issues one ticket per purchased unit from the existing order lines. Issuance is idempotent; tickets link to the existing order item, attendee, and event rather than copying separate order or event records.
- Attendees can browse their own paginated ticket wallet and open ticket details with a locally generated QR code. Ticket credentials are signed, transiently returned for attendee use, and stored server-side only as a hash and key version; raw codes are not persisted in scan records or application logs.
- Gate-staff access is invitation-only. Invitations are single-use and expire after 24 hours. New invitees become verified `gate_staff` users; an existing attendee or organizer must sign in as the invited email, keeps their existing role, and receives only an event-scoped assignment. Public registration never offers the `gate_staff` role.
- Verified organizers manage event-owned gates, send/revoke invitations, remove event assignments, enroll/revoke devices, and view server-confirmed admission counts for their own events. Attendees with a ticket/order line for an event cannot be assigned to scan that event; the server rechecks this at scan time.
- Assigned staff select only their assigned events, active gates, and their own active devices. Online scans return exactly `admitted`, `already_used`, `wrong_event`, `cancelled_or_refunded`, or `invalid_code`; an already-used result includes the original admission time and gate when that admission remains canonical. Scan requests are limited to 60 per minute per authenticated token.
- Offline operation requires a complete event roster snapshot downloaded from the registered device. The client verifies the signed manifest and roster hash before enabling offline scans. Snapshots expire after 24 hours; the server retains them for late synchronization for 30 days.
- Pending offline attempts retain the original device timestamp and reported outcome in an encrypted browser queue. Sync recomputes outcomes on the server; device-reported timestamps and outcomes are untrusted. Disconnected gates may both admit the same ticket; synchronization preserves both attempts, marks the ticket `admission_conflict`, and clears the canonical gate/time without choosing a winner.
- The scanner uses the browser's native QR detector where supported and provides manual code entry as a fallback. Offline use requires a registered device and its verified snapshot.

### Shared Integration Rules

- One Laravel application serves the same-origin Blade/Vite frontend and JSON API. API routes live in `routes/api.php`; browser and email-link pages live in `routes/web.php`.
- Modules share the existing users, events, orders, and order items. Module C ticket issuance consumes paid orders; it does not create duplicate users, events, or order catalogs.
- Authorization is enforced by the API and services: attendees access only their own holds, orders, and tickets; organizers manage only their own events; gate staff scan only their assigned events and registered devices.
- No seeded demo accounts, live Stripe activity, or production data are created by the application seed fixtures.

## Technology

- PHP 8.3 or later and Laravel 13.
- Laravel Sanctum personal access tokens for API authentication.
- Stripe Checkout and Stripe webhooks for payment status, payment details, and refunds.
- Eloquent and migrations for persistence. SQLite is the default local database; MySQL, MariaDB, PostgreSQL, and SQL Server are configured as alternatives.
- Queued Laravel mailables for payment and refund confirmations. Resend is the default mail transport.
- Blade, Vite, and Tailwind serve the attendee and organizer frontend from the Laravel application.
- XAF is the application currency. Ticket prices, order amounts, ledger amounts, and refunds are whole integer XAF values; there is no USD conversion or fractional XAF unit.

## Setup

Install PHP dependencies:

```bash
composer install
```

On Windows PowerShell, copy `.env.example` to `.env`; on macOS/Linux, use `cp .env.example .env`. Then set the application key, prepare the database, and run migrations:

```bash
php artisan key:generate
php artisan migrate
npm install
npm run build
```

The default connection is SQLite at `database/database.sqlite`. Create that file if it does not exist, or configure `DB_CONNECTION`, `DB_DATABASE`, and the appropriate connection values in `.env` for another database.

Configure the integrations below before testing real email or Stripe flows. Start the API and its background workers in separate terminals:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
```

The scheduler expires holds every minute. Run `npm run dev` during frontend development or build assets with `npm run build` for production.

### Existing databases

The canonical business migration now defines XAF-specific amount columns and removes the legacy USD-cent/decimal ledger fields. An existing database that already ran an earlier version of this migration will not be transformed by `php artisan migrate`. For the approved disposable local database, run `php artisan migrate:fresh` to rebuild the schema; this **deletes all data**. Do not run it against data that must be retained. A deployment with existing USD history requires a separate migration preserving its original currency and amounts.

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
| `GATE_CLIENT_INVITATION_URL` | Browser URL for `/gate-invitation/accept`; must be reachable by invited staff. The sample derives it from `APP_URL`. |
| `TICKET_ACTIVE_KEY_ID`, `TICKET_SIGNING_KEYS` | Versioned ticket-credential HMAC key ID and JSON map of base64-encoded, randomly generated keys (at least 32 bytes each). |
| `GATE_SNAPSHOT_KEY_ID`, `GATE_SNAPSHOT_PRIVATE_KEY`, `GATE_SNAPSHOT_PUBLIC_KEYS` | Versioned snapshot signing key ID, base64-encoded PEM private key, and JSON map of base64-encoded PEM public keys. |

### Module C local signing keys

Generate a ticket key and ECDSA P-256 snapshot key pair in PowerShell. Keep the private key in a secret store or a private local file; never commit it or place it under `public/`.

```powershell
New-Item -ItemType Directory -Force storage/app/private | Out-Null
$ticketKey = php -r "echo base64_encode(random_bytes(32));"
openssl ecparam -name prime256v1 -genkey -noout -out storage/app/private/gate-snapshot-private.pem
openssl pkey -in storage/app/private/gate-snapshot-private.pem -pubout -out storage/app/private/gate-snapshot-public.pem
$privateKey = [Convert]::ToBase64String([IO.File]::ReadAllBytes("storage/app/private/gate-snapshot-private.pem"))
$publicKey = [Convert]::ToBase64String([IO.File]::ReadAllBytes("storage/app/private/gate-snapshot-public.pem"))
```

Set the resulting values in `.env`:

```dotenv
TICKET_ACTIVE_KEY_ID=ticket-v1
TICKET_SIGNING_KEYS='{"ticket-v1":"<ticketKey>"}'
GATE_SNAPSHOT_KEY_ID=snapshot-v1
GATE_SNAPSHOT_PRIVATE_KEY=<privateKey>
GATE_SNAPSHOT_PUBLIC_KEYS='{"snapshot-v1":"<publicKey>"}'
GATE_CLIENT_INVITATION_URL="${APP_URL}/gate-invitation/accept"
```

Replace placeholders with the corresponding PowerShell variable values. Preserve old key IDs and values during rotation until all tickets and snapshots signed with them have passed their retention periods. After editing `.env`, run `php artisan config:clear` and restart workers.

The sample uses `QUEUE_CONNECTION=database`; queued invitation and payment/refund emails require `php artisan queue:work`. For local email testing without a provider, set `MAIL_MAILER=log`; for delivery, use a configured mail transport such as Resend. Run `php artisan schedule:work` locally; in production invoke `php artisan schedule:run` every minute. The scheduler expires holds every minute and prunes snapshots after the 30-day retention period.

The Stripe webhook endpoint is `POST /api/stripe/webhook`. Configure Stripe to send `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `checkout.session.async_payment_failed`, `payment_intent.succeeded`, `charge.updated`, `charge.refunded`, and `refund.updated` events to it.

## Authentication And Roles

Registration requires `name`, `email`, `password`, `password_confirmation`, and `role`. The accepted roles are `attendee` and `organizer`. Registration and login return a Sanctum bearer token. Send it as `Authorization: Bearer <token>` to authenticated endpoints.

Organizer actions require both the `organizer` role and verified email. Attendees can manage only their own holds, orders, and refunds. The hold token hash, password, and remember token are hidden from serialized model responses. Login, registration, password recovery, email verification, and verification-notification routes are rate-limited.

## API

JSON API endpoints are under `/api`; browser pages use `routes/web.php`. Validation failures use Laravel's JSON validation response format. Monetary amounts use XAF-specific names such as `base_price_xaf`, `amount_xaf`, and `unit_price_xaf`, and are whole XAF units.

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
| `GET` | `/events` | Public | Browse published events; supports `name`, `town`, `date_from`, `date_to`, `min_price_xaf`, and `max_price_xaf`. Results are paginated. |
| `GET` | `/events/{event}` | Public for published events | Read a published event and its ticket types. Organizers may read their own unpublished events. |
| `GET`, `POST` | `/organizer/events` | Verified organizer | List owned events or create an event. |
| `GET`, `PATCH` | `/organizer/events/{event}` | Verified owner | Read or update an owned event. Event status is not changed through `PATCH`. |
| `POST` | `/organizer/events/{event}/publish` | Verified owner | Publish an owned draft event. Published and cancelled events cannot be republished. |
| `POST` | `/organizer/events/{event}/cancel` | Verified owner | Cancel an event, release active holds, and request refunds for purchased lines. |
| `GET`, `POST` | `/organizer/events/{event}/ticket-types` | Verified owner | List or create ticket types. Creation accepts `name`, `base_price_xaf`, `quantity`, and optional `discount`. |
| `PATCH`, `DELETE` | `/organizer/ticket-types/{ticketType}` | Verified owner | Update a ticket type or delete one with no hold/order history. |
| `GET` | `/organizer/events/{event}/inventory` | Verified owner | Return total, sold, held, and remaining inventory. |
| `GET` | `/organizer/sales_summary` | Verified organizer | Summarize order count, payments, refunds, and collected XAF for owned events. |

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

Checkout accepts one to twenty distinct hold IDs. Repeating a completed checkout request with the same user and idempotency key replays the saved response; a concurrent request with that key receives a conflict response. If Stripe is unreachable, checkout returns `503` and preserves the pending order and active holds. Retry with the same hold IDs and `Idempotency-Key`; the browser retains that key for the basket, and the server reuses the existing order and Stripe idempotency key. Other Stripe session-creation failures mark the order failed and release its active holds.

### Ticket wallet and gate operations

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| `GET` | `/my-tickets` | Attendee | Return the attendee's own issued tickets, paginated, with a transient signed code for ticket details and local QR rendering. |
| `POST` | `/gate-invitations/accept` | Public or invited authenticated user | Accept a single-use invitation; new invitees provide name/password, while existing users authenticate as the invited email. |
| `GET` | `/gate/access` | Authenticated | Return only the caller's assigned events, active gates, and own active devices for the scanner selector. |
| `GET`, `POST` | `/organizer/events/{event}/gates` | Verified owner | List or create event-owned gates. |
| `PATCH`, `DELETE` | `/organizer/gates/{gate}` | Verified owner | Update or deactivate an event-owned gate. |
| `GET`, `POST` | `/organizer/events/{event}/gate-staff/invitations` | Verified owner | List invitations/assignments or invite a staff email. |
| `DELETE` | `/organizer/events/{event}/gate-staff/invitations/{invitation}` | Verified owner | Revoke an unused invitation. |
| `DELETE` | `/organizer/events/{event}/gate-staff/{staff}` | Verified owner | Remove event access and revoke devices for that assignment. |
| `GET`, `POST` | `/organizer/events/{event}/gates/{gate}/devices` | Verified owner | List or enroll a gate device assigned to event staff. |
| `DELETE` | `/organizer/events/{event}/gates/{gate}/devices/{device}` | Verified owner | Revoke a registered gate device. |
| `GET` | `/organizer/events/{event}/entry-counts` | Verified owner | Return distinct server-confirmed admitted-ticket counts for the event. |
| `POST` | `/gate/events/{event}/gates/{gate}/scans` | Assigned staff | Perform an online scan and return the server decision. |
| `GET` | `/gate/devices/{device}/snapshot` | Assigned device user | Download or reuse the signed event roster in pages of 500. |
| `POST` | `/gate/devices/{device}/scan-sync` | Assigned device user | Submit up to 500 offline attempts for server reconciliation. |

Online scan outcomes are exactly `admitted`, `already_used`, `wrong_event`, `cancelled_or_refunded`, and `invalid_code`. A canonical `already_used` result includes the original admission time and gate. Offline mode requires downloading and verifying every snapshot page, roster hash, and signature before scanning. Snapshots are valid for 24 hours and retained for late sync for 30 days. The encrypted browser queue preserves attempts across re-authentication; sync results remain server-authoritative.

## Domain And Persistence

The main schema is defined in `database/migrations`. The standard Laravel migrations additionally create users, sessions, password-reset tokens, cache, queue, and Sanctum token tables.

| Table | Responsibility |
| --- | --- |
| `users` | Attendee and organizer accounts; organizer accounts require verified email for organizer actions. |
| `events` | Organizer-owned events with draft, published, or cancelled status. |
| `ticket_types` | Event ticket price in whole XAF, discount percentage, and total quantity. Effective price is calculated and exposed as `price_xaf`. |
| `holds` | Inventory reservations with owner, quantity, status, expiry, and a hash of the one-time release token. |
| `orders` | Checkout/payment state, total `amount_xaf`, currency (`xaf`), Stripe session/payment-intent IDs, and normalized payment-method fields. |
| `order_items` | Immutable checkout snapshots of ticket type, quantity, `unit_price_xaf`, subtotal, and associated hold. |
| `ledger_entries` | Idempotent payment/refund journal rows keyed by `reference_key`; signed `amount_xaf` is canonical. |
| `refund_requests` | Scoped refund attempts, affected order lines, reason, XAF amount, Stripe refund ID, idempotency key, state, and failure details. |
| `idempotency_keys` | Per-user checkout request claims and replayable responses. |
| `stripe_webhook_events` | Stripe event deduplication, processing attempts, and retry state. |
| `tickets` | One record per purchased unit, linked to the existing order item, attendee, event, and unit number; only credential hash and key version are stored. |
| `ticket_issuances` | Idempotent order-level marker for ticket issuance after settlement. |
| `gates` | Event-owned entry points with active/inactive status. |
| `gate_staff_invitations`, `gate_staff_assignments` | Single-use invitation history and event-scoped staff access without replacing existing attendee/organizer roles. |
| `gate_devices` | Staff-owned registered devices attached to event gates, with snapshot and revocation state. |
| `gate_device_snapshots`, `gate_device_snapshot_tickets` | Signed immutable device rosters and ticket hashes/statuses used for offline verification. |
| `scan_attempts` | Scan outcomes, code hashes, staff/gate/device/snapshot references, reported/reconciled results, and conflict flags; never raw codes. |

Order payment-method columns include type, Stripe payment-method ID, brand, last four, and a curated JSON details object. The application does not persist full Stripe payment payloads or card numbers. Ticket/order amounts are whole XAF values, and Stripe's zero-decimal XAF amounts are sent without scaling.

## Main Workflows

### Ticket reservation and checkout

1. An attendee creates a ten-minute hold. `HoldService` locks the event and ticket type, checks active and confirmed inventory, and stores only a hash of the release token.
2. `CheckoutService` validates ownership and hold state, locks inventory in stable order, snapshots ticket prices into order lines, then creates the Stripe Checkout session.
3. Checkout is idempotent per attendee and key. Stripe session metadata links the session and PaymentIntent to the local order.
4. `checkout.session.completed` confirms payment only after validating the session amount, currency, attendee, event status, and all holds. It confirms holds and writes one payment ledger entry.
5. PaymentIntent/Charge events store payment-method details. Confirmation mail is queued after both the paid order and payment details are available.

Holds expire every minute through the scheduler. Checkout sessions expire after 30 minutes. If a successful payment arrives after a hold is no longer valid, the order enters refund processing rather than confirming unavailable tickets.

### Refunds and event cancellation

Attendee refunds are full-order requests. Event cancellation requests refunds for the order lines belonging to that event; each refund request is idempotent and is reconciled with Stripe webhooks. Successful refunds create negative `amount_xaf` ledger entries, release only affected confirmed holds, update the order state, and queue a refund confirmation. The refund email shows the order's ticket lines; for a partial refund those lines describe the order, not necessarily only the refunded subset.

### Module C ticket issuance and gate admission

1. The first committed transition of a paid order dispatches `OrderSettled`; the ticket listener issues one ticket per order-item unit and is safe to retry. Payment-confirmation email timing remains separate.
2. The attendee wallet reads only the signed-in attendee's tickets. Ticket details generate the signed credential QR locally; credentials are transient and are not sent to a third-party QR service.
3. Organizers invite staff by email. Invitations are single-use and expire after 24 hours. New invitees receive `gate_staff`; existing attendees/organizers keep their role and gain only an event assignment. Public registration cannot create gate staff.
4. Organizers manage gates, event assignments, devices, and counts only for events they own. A ticket holder cannot scan the event for which they hold a ticket/order line; the server rechecks this at scan time.
5. Assigned staff scan online through the server or go offline only after verifying a complete signed snapshot. The server recomputes synced outcomes and preserves duplicate disconnected-gate attempts as a conflict; device-reported times and outcomes remain untrusted claims.

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
