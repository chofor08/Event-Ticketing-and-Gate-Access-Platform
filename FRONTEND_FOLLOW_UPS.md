# Frontend Follow-Ups

Complete these items when the frontend framework and client application are selected. The backend remains API-only until then.

- Choose and scaffold the frontend framework; connect it to the `Merged-Modules` API.
- Set `FRONTEND_URL` in the frontend deployment environment and configure the backend password-reset email URL to use it.
- Build registration with an explicit attendee/organizer role choice, login, forgot-password, and reset-password screens.
- Show email-verification and resend-verification UI for organizers only; attendees are not blocked by email verification.
- Build event discovery, event details, ticket selection, hold countdown, multi-hold checkout, Stripe redirect/return states, order history, and full-refund request flows for attendees.
- Build organizer event creation/edit/cancellation, ticket type management, inventory, and cancellation-refund status views; expose these only to verified authenticated organizers.
- Handle API validation/authentication errors, expired holds, failed/late payments and automatic refunds in the UI.
- Show event-specific cancellation refunds separately from an attendee's full refund of the remaining order balance.
- Configure the deployed frontend origin for CORS and Sanctum as appropriate to the selected client authentication approach; do not expose Stripe secret or webhook credentials to the client.
- Add end-to-end tests for attendee and organizer workflows across the frontend and API.
