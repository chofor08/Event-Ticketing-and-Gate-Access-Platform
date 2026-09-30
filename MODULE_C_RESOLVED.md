# Module C: Resolved Behavior and Integration

## Purpose and evidence rules

This document resolves the Module C ticket, credential, and gate-admission behavior against [MERGED_SEED_DATA_2.md](MERGED_SEED_DATA_2.md). It records three distinct things:

- **Observed:** behavior present in the scanned `offline-ticketing-system` source or the merged application.
- **Resolved:** integration behavior explicitly required by `MERGED_SEED_DATA_2.md`; this takes precedence over incompatible standalone Module C implementation details.
- **Unresolved / not found:** a contract, implementation, or decision not established by either source. No behavior is inferred for these items.

The Module C source scanned is `offline-ticketing-system/ticket-confirmation-API`. The registration and inventory modules were also checked for role and order dependencies. The standalone Module C README is the stock Laravel README and does not document module behavior; behavior below is derived from its routes, services, models, migrations, and tests.

The integration decisions below were confirmed during implementation review. The merged application contains the Module C API, attendee wallet/detail view, invitation acceptance page, organizer gate/staff/device management, assigned-staff scanner, and encrypted offline scan queue.

## Module C behavior observed in source

### Ticket issuance

- `TicketIssuanceService::issue()` locks the order row and returns without issuing when the order is not `paid` or an issuance record already exists for the order.
- For every order-item quantity, it creates one ticket. The ticket copies order, order item, ticket type, event, and attendee identifiers; the event comes from the item’s ticket type.
- `ticket_issuances.order_id` is unique, providing one order-level issuance marker. Tickets also have a unique UUID `public_id` and a unique `credential` column.
- Ticket status starts as `issued`; the schema also permits `admitted`, `cancelled`, and `refunded`.
- The `IssueTickets` listener listens to `App\Events\OrderPaid` and calls the issuance service.

Evidence: [TicketIssuanceService.php](offline-ticketing-system/ticket-confirmation-API/app/Services/TicketIssuanceService.php), [IssueTickets.php](offline-ticketing-system/ticket-confirmation-API/app/Listeners/IssueTickets.php), [tickets migration](offline-ticketing-system/ticket-confirmation-API/database/migrations/2026_09_28_140837_create_tickets_table.php), [ticket issuances migration](offline-ticketing-system/ticket-confirmation-API/database/migrations/2026_09_28_185853_create_ticket_issuances_table.php).

### Ticket credentials

- A credential contains a ticket UUID, event ID, attendee ID, a random 32-byte nonce encoded as hex, and an HMAC-SHA256 signature using `app.key`.
- Verification checks the structure, nonce shape, signature, and numeric event and attendee IDs. A modified credential is rejected.
- The credential is stored as plaintext in `tickets.credential`. It is not written into the `scans` table by the scan service.
- The credential test covers successful identity verification and rejection of a tampered payload.

Evidence: [TicketCredentialService.php](offline-ticketing-system/ticket-confirmation-API/app/Services/TicketCredentialService.php), [TicketCredentialServiceTest.php](offline-ticketing-system/ticket-confirmation-API/tests/Feature/TicketCredentialServiceTest.php), [tickets migration](offline-ticketing-system/ticket-confirmation-API/database/migrations/2026_09_28_140837_create_tickets_table.php).

### Ticket access and scanning

- `GET /my-tickets` returns the authenticated user's tickets, with event and ticket type, newest first, paginated at 20 per page. The user relationship scopes by `attendee_id`.
- `POST /scan` requires Sanctum authentication and validates `credential`, an existing `event_id`, and a caller-provided gate string. The authenticated user's ID is recorded as `staff_id`.
- The scan service verifies the credential, loads and locks the identified ticket in a database transaction, checks credential ticket/event/attendee identity, checks requested event, checks refund and event/ticket cancellation state, and checks whether it was already admitted.
- A valid issued ticket is updated to `admitted` with admission time and gate. The service returns one of `admitted`, `already_used`, `wrong_event`, `invalid_code`, or `cancelled/refunded` and writes a scan row with ticket reference when resolved, event, staff, gate string, outcome, and scan timestamp. The scan row does not contain the credential.
- The online state transition uses a database row lock and transaction. The scanned Module C tests do not include issuance, scan authorization, scan outcome, or concurrent-admission tests.

Evidence: [api.php](offline-ticketing-system/ticket-confirmation-API/routes/api.php), [TicketController.php](offline-ticketing-system/ticket-confirmation-API/app/Http/Controllers/TicketController.php), [ScanController.php](offline-ticketing-system/ticket-confirmation-API/app/Http/Controllers/ScanController.php), [TicketScanService.php](offline-ticketing-system/ticket-confirmation-API/app/Services/TicketScanService.php), [scans migration](offline-ticketing-system/ticket-confirmation-API/database/migrations/2026_09_29_063613_create_scans_table.php).

## Resolved shared data and payment contract

- Reuse the merged `users`, `events`, `orders`, and `order_items` records and models. Do not import Module C copies of those tables or create duplicate event, user, or paid-order records.
- An order belongs to its attendee via `orders.user_id`; an order item identifies its ticket type via `order_items.ticket_type_id`; the ticket type identifies its event.
- Amounts crossing the Module C boundary are integer cents. Issue tickets only for paid orders and derive each event and quantity from the actual order items and their ticket-type relationship.
- The shared `tickets` table has one row per purchased unit, linked to `order_item_id`, attendee `user_id`, and `event_id`, with `unit_number`. Enforce uniqueness on `(order_item_id, unit_number)` so issuance is idempotent at unit level. Do not copy the standalone table shape without reconciling it to this definition.
- The merged application uses a versioned, stateless HMAC-SHA256 ticket credential derived from the ticket UUID, event, attendee, and key ID. It stores only the credential hash and key ID; the raw QR value is regenerated for attendee responses and is never stored in ticket or scan records.
- Merged ticket statuses are `issued`, `admitted`, `cancelled`, `refunded`, and `admission_conflict`. A conflict has no canonical gate or admission timestamp; scan attempts remain preserved.
- The payment flow must dispatch a separate `OrderSettled` event after the transaction's first transition to `paid`; a queued integration listener then builds and publishes the agreed ticket payload. Preserve `OrderPaid` for its current payment-confirmation email timing, which may be after payment details arrive. Do not attach issuance to the existing `OrderPaid` email event.
- The resolved payload retains the event/order/user/items/currency envelope. Each item maps `product_id` to existing `ticket_type_id` and includes `order_item_id`, associated `event_id`, `quantity`, and integer `unit_price_cents`; total is integer `total_cents`; currency remains an ISO currency code.
- `OrderSettled::toModuleCPayload()` now implements this mapping using the merged cents-based schema. It does not use the seed-described legacy `$this->total` accessor, which does not match `orders.amount_cents`.

Evidence: [seed integration constraints](MERGED_SEED_DATA_2.md), [merged orders migration](database/migrations/2026_09_27_000001_create_event_ticket_payment_tables.php), [Orders.php](app/Models/Orders.php), [OrderItems.php](app/Models/OrderItems.php), [OrderPaid.php](app/Events/OrderPaid.php), [StripeWebhookService.php](app/Services/StripeWebhookService.php), [OrderPaidConfirmation.php](app/Listeners/OrderPaidConfirmation.php).

## Resolved roles, gates, and scan authorization

- Public registration remains limited to `attendee` and `organizer`; users cannot self-assign gate access.
- Gate-staff onboarding is invitation-only. Invitations are single-use and expire after 24 hours. A newly invited user receives `gate_staff`; inviting an existing attendee or organizer does not replace their existing role.
- An event assignment grants scan capability only for that event. Organizer gate management must verify event ownership; gate staff can scan only events assigned to them.
- Do not assign an attendee to scan an event for which they have a ticket/order line. Recheck this condition at scan time because the attendee may purchase after assignment.
- A scan request must target a gate record belonging to its selected event. Limit scan requests to 60 per minute per authenticated gate-staff token.
- Only the verified event organizer enrolls, lists, and revokes devices for gates they own. A device belongs to one staff user and one event-owned gate. The assigned user must authenticate for snapshots and sync; a revoked device or inactive gate cannot scan.
- The organizer-only entry-count endpoint is scoped to events owned by the caller and counts distinct admitted tickets, not scan attempts.
- Attendees can access only their own tickets. Organizers can manage gates and view entry data only for events they own. Gate staff can use scan operations only for assigned events.
- A new invitee may create an email-verified `gate_staff` account by accepting a valid emailed invitation. Existing attendees and organizers authenticate as the invited email, keep their role, and receive only the event assignment.
- Devices download a complete immutable event snapshot in pages of 500. The server signs each manifest; the device verifies it with the corresponding versioned public key. Snapshots are valid for 24 hours and retained for 30 days for late sync.
- The snapshot GET reuses the device's current unexpired snapshot; there is no early-refresh option. Tickets issued or changed after snapshot creation appear in a new snapshot after the current one expires. A code missing from the local roster can be checked through the online scan route when connected.
- Offline scan times and outcomes are untrusted device claims. Sync verifies QR signatures and snapshot membership, recomputes results against current ticket/refund/event state, retains the original claim, and accepts late uploads within the 30-day retention period with a flag.
- Claimed scan times outside the signed snapshot interval are retained and flagged, but do not by themselves change the server-computed ticket result.
- Separate disconnected gates may both admit the same ticket. Sync preserves both attempts, marks the ticket `admission_conflict`, clears canonical gate/time, and counts the ticket at most once when a valid admitted result remains. No winning scan is selected.
- A QR absent from the local event snapshot is `invalid_code` on-device. Sync can reclassify it as `wrong_event` only after server-side verification proves it is a valid ticket for another event.
- Ticket and snapshot signing keys are separately deployment-managed and versioned. Old verification keys must remain configured for existing tickets and snapshots through their retention periods.

Evidence: [seed role and gate constraints](MERGED_SEED_DATA_2.md), [merged registration role validation](app/Http/Controllers/Api/Auth/RegisterController.php), [merged API routes](routes/api.php).

## Clash register and resolution

| Area | Observed standalone / merged behavior | Resolved behavior and clash |
|---|---|---|
| Ticket schema | Standalone Module C stores duplicate order/event/ticket data and a plaintext credential. | The merged migration uses the existing order item, user, and event; adds `unit_number` with unique `(order_item_id, unit_number)`; and stores only the credential hash and key ID. |
| Issuance idempotency | Module C uses a unique order-level `ticket_issuances` row and a pre-check; its ticket schema has no per-unit uniqueness key. | Preserve issuance idempotency and enforce the seed's per-order-item unit uniqueness. Do not add a duplicate order/event data store. |
| Payment trigger | Standalone listener consumes `OrderPaid`. Merged `OrderPaid` is dispatched from payment processing for confirmation email, including when payment details arrive after settlement. | Dispatch `OrderSettled` once, after the first committed transition to paid, for ticket integration. Keep `OrderPaid` for email. |
| Payload source and money | The seed describes a legacy helper reading `$this->total`; merged orders use `amount_cents`. | `OrderSettled::toModuleCPayload()` now emits the agreed envelope and integer cents fields from existing order/item rows. |
| Credential storage | Standalone Module C stores the full credential in `tickets.credential`. | The merged credential is a versioned HMAC value. Only its SHA-256 hash and key ID are stored; raw codes are transient. |
| Scan outcomes | Code returns `cancelled/refunded`; other values include `admitted`, `already_used`, `wrong_event`, and `invalid_code`. | Normalize to the seed's exact outcome set: `admitted`, `already_used`, `wrong_event`, `cancelled_or_refunded`, `invalid_code`. Record every outcome. |
| Gate representation | Scan accepts an arbitrary gate string; the scan schema stores that string. There is no gate table or event/staff assignment check in these routes/services. | Use event-owned gate records and enforce event ownership and event-scoped staff assignment before invoking the admission service. Record the gate identity. |
| Role enforcement | Module C scan route applies only `auth:sanctum`; it treats any authenticated user as staff. Merged registration accepts only attendee/organizer. | Public registration stays unchanged. New invitees receive `gate_staff`; existing roles are retained. Event assignment and device ownership authorize scans. |
| Ticket-holder staff conflict | No assignment flow or scan-time ticket-holder exclusion was found in Module C source. | Reject assignment to an event where the attendee has an order line/ticket and recheck at scan time. |
| Scan deduplication and concurrency | Standalone online service locks tickets but has no attempt idempotency key or concurrency tests. | Merged attempts use unique `attempt_id`; online scans lock ticket rows; offline sync processes attempts independently and preserves conflicts without selecting a winner. Production-engine concurrency testing remains required. |
| Token expiry | Merged Sanctum previously had no configured expiration. | Sanctum expiration is 10,080 minutes (seven days) for all bearer tokens. |
| Offline operation | Standalone Module C has no offline cache or sync path. | Merged APIs provide signed 24-hour snapshots and per-attempt sync. Separate offline gates can admit the same ticket; sync flags duplicates without choosing a winner. Device timestamps/outcomes remain unverified claims. |
| Entry counts and performance | Standalone Module C has no entry-count route or 60/minute throttle. No deployed scan latency measurement exists. | Merged API provides owner-scoped distinct-ticket counts and a 60-per-minute token limit. Measure the under-300-ms target after deployment. |

Evidence: [Module C API routes](offline-ticketing-system/ticket-confirmation-API/routes/api.php), [scan controller](offline-ticketing-system/ticket-confirmation-API/app/Http/Controllers/ScanController.php), [scan service](offline-ticketing-system/ticket-confirmation-API/app/Services/TicketScanService.php), [scan schema](offline-ticketing-system/ticket-confirmation-API/database/migrations/2026_09_29_063613_create_scans_table.php), [merged Sanctum config](config/sanctum.php), [seed offline limitation](MERGED_SEED_DATA_2.md).

## Scan decision and logging ownership

- The merged `TicketScanService` owns ticket credential verification, scan decisions, ticket state, online row locking, and offline result reconciliation.
- The API layer owns authentication and authorization: event assignment, event ownership, gate/event matching, device ownership/revocation, and ticket-holder exclusion. It records each validly structured attempt with its attempt ID, timestamp claim, staff, gate, source, and ticket reference/hash when resolvable.
- Raw submitted QR credentials are transient and are not persisted. Offline attempts keep the device-reported outcome separately from the server-reconciled outcome; device time is explicitly unverified.
- Required outcomes are exactly `admitted`, `already_used`, `wrong_event`, `cancelled_or_refunded`, and `invalid_code`.
- Entry counts aggregate distinct tickets whose current status and reconciled scan result remain admitted within organizer-owned events; they do not count scan attempts.

## Current Integration Status

The merged backend includes shared per-unit ticket issuance from `OrderSettled`, attendee ticket retrieval, event-owned gates, email invitations, event assignments, organizer-managed devices, online scans, signed snapshots, and per-attempt offline sync. The frontend includes a paginated attendee wallet with locally rendered signed-code QR details, invitation acceptance, organizer gate/staff/device management, assigned-event online scanning, signed snapshot verification, and an encrypted IndexedDB offline queue. Online and server-reconciled results use the exact outcomes `admitted`, `already_used`, `wrong_event`, `cancelled_or_refunded`, and `invalid_code`.

Approved route families are:
- `GET /api/my-tickets`
- `GET /api/gate/access` (authenticated user's assigned events, active gates, and own active devices)
- Organizer gate management and entry counts under `/api/organizer/events/{event}`
- Organizer invitations under `/api/organizer/events/{event}/gate-staff/invitations`
- `POST /api/gate-invitations/accept`
- Organizer device list/enrollment/revocation under `/api/organizer/events/{event}/gates/{gate}/devices`
- `POST /api/gate/events/{event}/gates/{gate}/scans`
- `GET /api/gate/devices/{device}/snapshot?snapshot_id={id}&cursor={id}`
- `POST /api/gate/devices/{device}/scan-sync`

Remaining deployment/client work:

- Configure `TICKET_ACTIVE_KEY_ID` and `TICKET_SIGNING_KEYS`; configure the snapshot private key and versioned public-key map; set `GATE_CLIENT_INVITATION_URL`; and configure a production queue/mail transport. The sample environment intentionally contains no secret material.
- The scanner uses the browser's native `BarcodeDetector` when available, with a manual ticket-code entry fallback. Browsers without `BarcodeDetector` do not have camera scanning.
- The client uses the current snapshot until it expires after 24 hours; no early-refresh parameter exists. It uses online scanning for codes absent from its roster when connectivity is available.
- Run the scheduler so expired snapshots are pruned after the 30-day sync retention period.
- Add or run concurrency tests against the production database engine and measure online scan latency after deployment; SQLite feature tests do not establish production lock behavior or the 300-ms target.
- The approved design does not authenticate device-reported times/outcomes with per-attempt signatures. Sync flags these as unverified and recomputes server outcomes. Disconnected gates can admit the same ticket before sync; the API reports this conflict but cannot undo physical entry.
