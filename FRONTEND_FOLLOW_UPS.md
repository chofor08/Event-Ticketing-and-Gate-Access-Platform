# Frontend Follow-Ups

## Implemented

- The selected frontend is Laravel Blade with Vite, served from the same origin as the API. Browser pages and email landing pages use `routes/web.php`; JSON endpoints remain in `routes/api.php`.
- The client uses Sanctum bearer tokens and session storage. It does not use Sanctum stateful-cookie authentication, so same-origin CORS and CSRF configuration is not part of this client flow.
- Attendee and organizer screens cover authentication, password recovery, event discovery, ticket selection, holds, Stripe checkout return states, orders/refunds, event/ticket management, inventory, sales, and event-cancellation refund status.
- The attendee ticket wallet lists issued tickets with pagination and opens ticket details; signed credentials stay in page memory and are revealed only on demand.
- Ticket detail generates the signed-code QR locally in the browser; the copy-code action remains as a fallback.
- Gate invitation acceptance supports new invitees and existing signed-in users without expanding public registration roles.
- Organizer event panels manage event-owned gates, invitations, event assignments, per-gate devices, and server-confirmed admitted-ticket counts.
- Gate staff can select only assigned events, active gates, and registered devices; online `already_used` results include the original admission time and gate.
- The gate client verifies complete signed snapshots before offline use and stores pending attempts in an AES-GCM-encrypted IndexedDB queue for batch synchronization.
- Bearer tokens issued by the frontend are age-checked against the configured seven-day lifetime; `401` responses prompt re-authentication.
- Organizer event lifecycle is exposed through a dedicated draft-to-published action and cancellation action; cancelled events are terminal, and normal edits cannot change status.
- Ticket prices, filters, order totals, refunds, ledger values, and email displays use whole XAF amounts. Stripe receives XAF amounts without conversion or scaling.
- Reset email links use `/password-reset/{token}?email={email}` and the client submits both values to the reset API. The generated-link round-trip has feature-test coverage. Tokens expire after 60 minutes; reset-token generation is throttled for 60 seconds.

## Remaining

- Set `FRONTEND_URL` to the deployed application origin so password-reset emails open the deployed Blade page. Do not expose Stripe secret or webhook credentials to the browser.
- Add automated browser end-to-end tests for attendee and organizer workflows when a browser-test dependency is approved. Current coverage is PHPUnit plus manual browser smoke checks.
- If a newly requested reset link still reports invalid, confirm the request and email are using the same app/database; use the newest link for the matching account within 60 minutes.

The app is currently same-origin, so do not add CORS or Sanctum cookie configuration unless the frontend is later deployed on a separate origin or changes authentication strategy.

## Module C Deployment And Verification

The attendee wallet, invitation, organizer access-management, online scanner, and offline queue are implemented. Remaining client work:

- Configure the versioned ticket/snapshot keys, `GATE_CLIENT_INVITATION_URL`, production mail/queue, and scheduler for each deployment.
- Add automated browser end-to-end coverage for attendee, organizer, and gate-staff workflows when a browser-test dependency is approved; current verification uses PHPUnit and manual smoke checks.
