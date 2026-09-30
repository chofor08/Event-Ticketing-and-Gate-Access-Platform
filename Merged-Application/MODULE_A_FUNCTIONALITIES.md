# Module-A Functionalities in Merged-Modules

This file lists only Module-A's event and ticket-inventory functionality

## Event Management

- Organizers create events and provide a status at creation: `draft`, `published`, or `cancelled`.
- Verified organizers can list and inspect their own events.
- Organizers can update event details; status changes use the dedicated cancellation operation rather than the normal update endpoint.
- Organizers can cancel their own events. Active holds are released, and paid order lines for that event are refunded without affecting other events in a mixed order.
- Public event discovery returns published events, including past events, ordered by latest event date.
- Public discovery supports event-name, town, date-range, and ticket-price filters.
- Public event details are available for published events; unpublished event details are limited to their organizer.

## Ticket Types and Inventory

- Verified organizers can create, list, and update ticket types for their own non-cancelled events.
- Ticket types support a name, integer USD-cent base price, percentage discount, and ticket quantity.
- Inventory reports include sold, currently held, and remaining quantities.
- Ticket types cannot be reduced below quantities already held or sold.
- Ticket types with any hold history or order lines cannot be deleted, preserving the audit trail.
- Module-A ticket types and holds are the merged app's only inventory source; Module-B's simulated generic `items` catalog is excluded.

## Attendee Holds

- Authenticated attendees can hold tickets only for published future events.
- A hold records its attendee, ticket type, quantity, status, and expiration; its token is returned at creation and only its hash is stored.
- Hold creation locks inventory while checking availability, preventing concurrent overselling.
- Holds expire after 10 minutes. Active holds reduce availability; a scheduled task marks expired holds as expired.
- Attendees can release their own holds using the returned token. Holds already attached to an order cannot be released directly.
- Checkout accepts multiple active holds belonging to the authenticated attendee and records them against ticket-aware order lines.

## Module-A Operations Not Exposed

- Manual hold confirmation is not exposed; successful Stripe payment webhooks confirm holds.
- The `holds:attempt` development command is not included because it can create a hold without an authenticated attendee identity.
