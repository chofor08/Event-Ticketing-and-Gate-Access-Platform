# Merged Seed Data And Module C Integration Constraints

This file records the resolved data and integration constraints for adding Module C (tickets, access, and gate operations) to the existing merged application. It is not a production-data seeder.

## Shared Sources Of Truth

- Reuse the existing `users`, `events`, `orders`, and `order_items` tables and Eloquent models. Do not add duplicate minimal event, user, or paid-order tables.
- An event is owned by the existing organizer through `events.organizer_id`.
- An order belongs to the attendee through `orders.user_id`.
- Purchased event and quantity information comes from `order_items` and its `ticket_type` relationship. Each ticket type belongs to the existing event.
- Payment amounts are integer cents. An order is eligible for ticket issuance only when its status is `paid`.
- The existing `OrderPaid` event carries an `Orders` model. Ticket issuance must be idempotent, because payment/webhook processing can be retried.
- Public registration currently accepts only `attendee` and `organizer`; it must not allow self-granting gate access. Invitation/assignment adds event-scoped scan capability without replacing the user's existing attendee or organizer role.
- Sanctum bearer tokens for all roles expire after seven days.
- Gate-staff onboarding is invitation-only. Invitations are single-use and expire after 24 hours.
- Gates are event-owned records. Organizer gate management must verify event ownership; gate staff access remains scoped by event assignment. An attendee may be assigned to scan another event, but not an event for which they hold a ticket.
- New invitees use the `gate_staff` role. Existing attendee and organizer roles are not replaced by an invitation; an event assignment grants scan capability only for that event. Reject an attendee assignment if the attendee already has a ticket/order line for that event, and recheck at scan time to cover later purchases.
- The scan endpoint limit is 60 requests per minute per authenticated gate-staff token.
- Scan requests target a gate belonging to the selected event. The entry-count endpoint is organizer-only and returns counts scoped to events owned by the caller.
- Scan response time target is under 300 ms for the online scan path; measure it in the deployed environment.
- Core ticket admission decisions and ticket-locking/concurrency logic are owned by the Module C admission implementer. Do not duplicate or independently redefine those rules in the merged-module integration.
- The merged scan adapter is responsible for authorization and recording every returned scan outcome; the delegated admission service remains responsible for deciding the outcome and ticket state. The adapter must never persist the raw ticket code.
- Define the shared base `tickets` table here with one row per purchased unit, linked to `order_item_id`, attendee `user_id`, and `event_id`, plus `unit_number`. Enforce uniqueness on (`order_item_id`, `unit_number`) for issuance idempotency. Ticket-code verification and admission-state fields are owned by the admission-engine implementation and must be agreed with that owner before they are added.
- The admission engine owns scan retry deduplication; the merged adapter must use the engine's idempotency/result contract when writing attempt logs.

## Resolved Module C Invariants

- Issue one individual ticket per purchased unit after payment succeeds.
- A ticket code must be unguessable and reject alterations. Ticket validation and status must not depend on trusting a caller-supplied ticket ID.
- Never persist or write the raw ticket code to scan logs or application logs.
- Attendees may access only their own tickets. Organizers may manage gate access and view entry data only for events they own. Gate staff may access scan operations only for events to which they are assigned.
- Every scan attempt produces one outcome: `admitted`, `already_used`, `wrong_event`, `cancelled_or_refunded`, or `invalid_code`.
- A ticket admits at most once through online concurrent scans. The admission state transition must be atomic and covered by a concurrency test.
- Record every scan attempt with its ticket reference when resolvable, staff identity, gate identity, scan timestamp, and outcome. Invalid codes may have no resolvable ticket reference.
- The scan log never stores the raw ticket code. The scan endpoint may receive the code transiently for verification.
- Organizer entry counts are scoped to owned events and count admitted tickets, not every scan attempt.
- Use factories and test fixtures for attendees, organizers, staff, existing events, paid orders/order lines, tickets, assignments, gates, and scan attempts. Test/development seed fixtures must not create real payment activity, live Stripe sessions, or production data.

## Existing Paid-Order Integration Shape

The current `App\\Events\\OrderPaid` carries an `App\\Models\\Orders` instance and is also used for payment confirmation email after payment details arrive. It is not the immediate ticket-issuance trigger.

Module B's integration is expected to call `Orders::toModuleCPayload()` from a listener. Its current helper returns these keys: `event` (`order.paid`), `order_id`, `user` (`id`, `email`), `items` (`product_id`, `quantity`, `unit_price`), `total`, and `currency`. The helper maps `product_id` from `order_items.item_id` and `unit_price` from the Module-B decimal column.

For the merged adapter, retain the event/order/user/items/currency envelope, map `product_id` to the existing `ticket_type_id`, include `order_item_id` and the associated `event_id` on each item, and use explicit integer `unit_price_cents` and `total_cents` fields. Currency remains the ISO currency code. This mapping was confirmed for the integration.

The Module-B helper still has a source/schema mismatch: its `orders` migration defines `amount`, but `toModuleCPayload()` reads `$this->total`. The merged adapter must use the existing cents-based fields rather than copying that broken accessor. The exact Module C receiving interface is not present in this workspace; the merged side will expose the agreed payload through a dedicated application event for the admission/ticket implementer to consume.

Ticket issuance consumes the paid order and order-item quantities, not a copy of order or event data, and must be idempotent. Dispatch a separate `OrderSettled` event after the transaction's first transition to `paid`; its queued integration listener calls `toModuleCPayload()`. Keep the existing `OrderPaid` event for email timing after payment details become available.

## Explicit Offline Limitation

Two isolated gates that are both offline cannot know that the same ticket was admitted at the other gate. Therefore offline synchronization can identify and report duplicate admissions after reconnect, but cannot guarantee that only one person physically entered in that situation. A conflict resolution policy and any gate/device partitioning rule must be approved before offline duplicate handling is considered complete. No winning-scan policy is assumed here.
