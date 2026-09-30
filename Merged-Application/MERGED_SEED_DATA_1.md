# Merged Module Integration Decisions

This is the controlling integration specification for `Merged-Modules`. Keep Module-A and Module-B unchanged. Prefer minimal changes and direct rewrites/adaptations of their existing code; introduce abstractions or schema only when required by these decisions.

## Project

- `Merged-Modules` is a fresh Laravel application, separate from both source modules.
- Provide the JSON API and a same-origin Laravel Blade/Vite frontend in one application.
- Keep JSON endpoints in `routes/api.php`; use `routes/web.php` for browser pages and signed email-link landing pages. Do not move API endpoints into the web middleware group.
- Use one application, one API route set, one configuration set, and one canonical migration history.
- Do not add demo users or seeded catalog data.
- The frontend, lifecycle, and XAF decisions below are implemented in the current application.

## Authentication and Roles

- Use one `users` table and one `User` model with Sanctum API tokens.
- Adapt Module-B's rate-limited login, password reset, and email-verification behavior.
- Registration requires an explicit `attendee` or `organizer` role. Do not choose a silent default.
- Organizer registration activates the organizer role immediately, but organizers must verify their email before organizer actions.
- Attendees do not need email verification for holds, checkout, or refunds.
- Enforce roles and ownership in API middleware/services: organizers manage only their own events and attendees act only on their own holds and orders.
- Logout revokes all Sanctum tokens for the authenticated user, preserving Module-B's behavior.

## Event and Inventory Rules

- Module-A is the sole inventory source: events, ticket types, and holds.
- Require event status at creation, preserving Module-A's `draft`, `published`, or `cancelled` values.
- Allow an existing `draft` event to transition to `published` only through a dedicated publish action. Do not allow `published` to return to `draft`.
- Allow `draft` or `published` events to transition to `cancelled` only through the dedicated cancellation action. Cancellation is terminal; cancelled events cannot be reopened or republished.
- Normal event-detail updates do not accept or change status.
- Expose publishing as `POST /api/organizer/events/{event}/publish`; only the verified owner may publish an event whose current status is `draft`.
- Only published future events may have attendee holds.
- Public discovery includes all published events, including past events, as in Module-A; the future-date restriction applies to holds.
- Holds last 10 minutes. Expired holds stop counting against availability and are marked expired by a scheduled task.
- Hold creation checks available quantity under a database lock.
- Preserve hold/order audit history: a ticket type with any hold history or order line cannot be deleted; return a validation error instead of relying on a foreign-key failure.
- Do not expose manual hold confirmation; successful Stripe payment webhooks confirm holds. The Module-A `holds:attempt` development command is omitted because it creates holds without an attendee identity; the authenticated attendee API is the supported entry point.
- Checkout accepts multiple holds, including tickets from multiple events, only when every hold belongs to the authenticated attendee and is active.
- Checkout authorization uses authenticated ownership; the hold token is not required for checkout.
- Preserve Module-B's attendee order-line listing endpoint at `/order_items`, adapted to return ticket/event data and scoped to the authenticated attendee.
- Preserve Module-B's `/refund` request shape (`id` in the request body) and `/sales_summary` path, with the role and ownership restrictions in this specification.
- Preserve Module-A's descending `latest(date)` order for public event listings.
- Do not expose ticket types for cancelled events through the organizer ticket-type listing endpoint.
- Bind every order to the authenticated attendee and each order line to its `ticket_type_id` and `hold_id`.
- Ticket prices are entered directly in whole XAF; do not convert USD prices using an implicit or hard-coded exchange rate.
- XAF has no fractional minor unit. Stripe checkout, order snapshots, refunds, and ledger entries use integer XAF amounts. Use currency-neutral or XAF-specific field names rather than `_cents`.
- New events, orders, and refunds use XAF (`xaf`). Display XAF consistently in the frontend, emails, and organizer sales reporting.
- The current local database is disposable and must be rebuilt after the XAF schema change; do not reinterpret existing USD-cent values as XAF. If any non-disposable USD history must be retained, pause for a separate migration decision that preserves its original currency and amounts.
- Do not include Module-B's simulated `items` table, model, factory, seeder, or item endpoints.

## Payment, Orders, and Refunds

- Retain Module-B's Stripe Checkout, signed webhook verification, orders, ledger, idempotency, and payment/refund email behavior.
- Do not create a Stripe Checkout session until every requested hold has been validated and the order/price snapshot recorded.
- The Stripe Checkout session lasts 30 minutes; webhook events, not the browser return route, determine payment status.
- The browser success route only acknowledges the return; attendees retrieve their own order state through the authenticated orders endpoint.
- Preserve Module-B's `charge.updated` behavior: save currency/payment-method details and dispatch the payment confirmation email after the order is paid.
- Preserve Module-B logout semantics by revoking all Sanctum tokens belonging to the authenticated user.
- Payment and refund emails include transaction details; payment emails list ticket/event lines, and refund emails show the actual amount refunded.
- A successful payment confirms holds only if all the order's holds are still valid. If payment succeeds after any hold expires or becomes invalid, automatically refund the payment and do not confirm unavailable tickets.
- Checkout, refunds, and Stripe webhook processing must be idempotent.
- Record Stripe refund attempt counts. Reuse the logical refund request key, but use a new Stripe idempotency key for each retry after a failed attempt.
- Process `refund.updated` and `charge.refunded` safely in either delivery order; settle each refund and its ticket lines only once.
- Attendees may request full refunds only for their own paid orders.
- If an organizer cancels an event in a multi-event order, refund only that event's order lines and release only those holds; unaffected lines remain paid/confirmed. Use an idempotent refund record per affected order/event.
- An attendee full-refund request refunds only the remaining unrefunded order balance and releases only the holds represented by those remaining order lines.
- Failed cancellation refunds remain recorded as failed for retry/resolution; do not silently mark them refunded.
- Restore Module-B's sales summary as an organizer-only report scoped to orders containing that organizer's event tickets.
- For a mixed-organizer order, attribute the full order payment and refund totals to each organizer represented in that order.
- Currency for the merged ticketing application is XAF. Store ticket prices, order totals, ledger amounts, refund amounts, and Stripe line amounts as whole integer XAF amounts.

## Canonical Schema

Use the fresh Laravel baseline once, including users, password reset tokens, sessions, cache/jobs, and Sanctum personal access tokens. The business schema includes:

- Module-A event, ticket type, and hold tables, with holds owned by users.
- Module-B payment tables for orders, order items, ledger entries, and idempotency keys.
- Ticket-aware order item keys and minimal refund/webhook records required for safe retries and partial event cancellation.

Do not copy both modules' duplicate baseline migrations. Do not migrate or seed Module-B's simulated item catalog.

## Frontend

The selected frontend is Laravel Blade with Vite, served from the same origin as the API. Authenticated frontend requests use Sanctum bearer tokens. CORS and Sanctum stateful-cookie configuration are not needed for this same-origin bearer-token client. Configure `FRONTEND_URL` to the deployed app origin so reset links open the Blade reset page.

Ticket and order amounts use whole XAF values in the `*_xaf` fields. XAF has no fractional minor unit; do not divide or multiply Stripe amounts by 100. Local disposable databases must be rebuilt with `php artisan migrate:fresh`; this deletes all data. Never reinterpret old USD-cent amounts as XAF.

See [FRONTEND_FOLLOW_UPS.md](../Merged-Modules/FRONTEND_FOLLOW_UPS.md) for the frontend implementation status and deferred Module-C scope.

## Password Reset

- Reset emails link to `/password-reset/{token}?email={email}`; the page submits both values to `POST /api/reset-password`.
- The password broker expires links after 60 minutes and throttles token generation for 60 seconds. Use the newest link for the same email and the same application/database that issued it.
- An integration test confirms the generated email action URL, frontend route, and reset API round-trip. A reported live invalid-token issue remains an environment/request-path check if a newly issued link still fails.

## Clarification Rule

If an implementation condition is not specified here or in the source behavior being preserved, pause and ask before choosing. After the answer, update this file with the resolved condition before continuing, so the decision does not need to be requested again.
