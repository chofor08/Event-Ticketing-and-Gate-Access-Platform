Event Ticketing and Ticket Hold API

A Laravel REST API for managing events, ticket types, and ticket holds.

The main focus of the project is keeping ticket inventory accurate when multiple users try to reserve tickets at the same time.

The Problem

A ticketing system can oversell tickets when two or more users make requests at the same time.

For example, if an event has 10 tickets remaining:

User A requests 6 tickets
User B requests 6 tickets

If both requests read the inventory before either request updates it, both requests could see 10 available tickets and succeed.

This would result in 12 tickets being reserved when only 10 exist.

The API prevents this using database transactions and row-level locking.

Application Structure

The controllers are kept thin. HTTP request handling is done in the controllers, while the main business logic is placed in service classes.

Request
   ↓
Route
   ↓
Controller
   ↓
Service
   ↓
Database Transaction
   ↓
Database

Ticket Hold Process

When a user creates a hold, the system:

1. Finds the requested ticket type.
2. Starts a database transaction.
3. Locks the ticket type row.
4. Checks the current available inventory.
5. Checks whether the requested quantity can be held.
6. Creates the hold if enough tickets are available.
7. Commits the transaction.

The row remains locked until the transaction finishes. Another transaction attempting to modify the same ticket inventory must wait until the current transaction has completed.

This prevents two concurrent requests from using the same available inventory.

Concurrency Control

Inventory-changing operations use database transactions and row-level locking.

The ticket type row is locked using Laravel's "lockForUpdate()" when the inventory needs to be checked and modified.

This is important because the available ticket quantity depends on the current holds and confirmed tickets.

The concurrency test checks that simultaneous hold requests cannot reserve more tickets than the configured ticket quantity.

The test is located in the "tests" directory.

Hold States

Holds can have the following states:

- "held" — The tickets are temporarily reserved.
- "confirmed" — The hold has been confirmed.
- "expired" — The hold passed its expiration time.
- "cancelled" — The hold was cancelled.

Expired and cancelled holds no longer consume available ticket inventory.

Holds are kept in the database after their status changes instead of being deleted.

Hold Expiration

A hold has an expiration time stored in "expires_at".

When the expiration time is reached, the hold is no longer considered active.

An "ExpireHolds" command is used to find expired holds and update their status.

The scheduler runs this command automatically.

Active Hold
     │
     │ expires_at reached
     ▼
Expired Hold
     │
     ▼
Tickets become available again

An expired hold cannot be confirmed.

Event Cancellation

When an organizer cancels an event:

1. The event is marked as cancelled.
2. Active holds associated with the event are released from the inventory.
3. The event can no longer accept new ticket operations.

The hold records remain available for record keeping.

Database Structure

The application uses four main tables.

Users

Stores organizer accounts.

- "id"
- "name"
- "email"
- "password"
- "role"
- "timestamps"

Events

Stores event information.

- "id"
- "organizer_id" — Foreign key
- "title"
- "description"
- "venue"
- "region"
- "date"
- "start_time"
- "close_time"
- "status"
- "timestamps"

Ticket Types

Stores the ticket categories and inventory for each event.

- "id"
- "event_id" — Foreign key
- "name"
- "price"
- "quantity"
- "timestamps"

Holds

Stores ticket reservations.

- "id"
- "ticket_type_id" — Foreign key
- "quantity"
- "token"
- "status"
- "expires_at"
- "timestamps"

Database Relationships

User
 │
 │ 1 ──── many
 ▼
Event
 │
 │ 1 ──── many
 ▼
TicketType
 │
 │ 1 ──── many
 ▼
Hold

The relationships are:

- A user can have many events.
- An event belongs to an organizer.
- An event can have many ticket types.
- A ticket type belongs to an event.
- A ticket type can have many holds.
- A hold belongs to a ticket type.

Features

Organizer

- Registration
- Login
- Create events
- View events
- Update events
- Publish events
- Cancel events
- Create ticket types
- Update ticket types
- Delete ticket types

User

- Search and browse events
- Search by location
- Search by ticket price
- Search by date range
- Create ticket holds
- Confirm holds
- Cancel holds

Event Search

Events can be filtered using:

- Location/region
- Ticket price
- Date range

Example:

GET /api/events?region=North-West&min_price=1000&max_price=5000

The exact query parameters depend on the implemented route.

API Routes

Authentication

POST /api/register
POST /api/login

Events

GET    /api/events
POST   /api/events
GET    /api/events/{event}
PATCH  /api/events/{event}

Event-specific actions include publishing and cancellation.

Ticket Types

GET    /api/events/{event}/ticket-types
POST   /api/events/{event}/ticket-types
PATCH  /api/ticket-types/{ticketType}
DELETE /api/ticket-types/{ticketType}

Holds

The hold routes provide operations for:

- Creating a hold
- Confirming a hold
- Cancelling a hold

Refer to "routes/api.php" for the current route definitions.

API Testing

The API can be tested using Postman or another HTTP testing tool.

The following areas are tested:

- Registration
- Login
- Event CRUD
- Ticket type CRUD
- Hold creation
- Hold confirmation
- Hold cancellation
- Hold expiration
- Validation
- Authorization
- Concurrent ticket holds

Concurrency Test

The project contains a feature test specifically for concurrent holds.

The test creates competing requests against the same ticket inventory and checks that the total number of successfully held tickets does not exceed the configured quantity.

Run the tests with:

php artisan test

The concurrency test can also be run separately using PHPUnit/Pest's test filtering options, depending on the test runner configuration.

Technology Stack

- PHP
- Laravel
- Eloquent ORM
- MySQL/MariaDB
- Laravel Scheduler
- PHPUnit/Pest
- Postman

Installation

Clone the repository:

git clone <https://github.com/lestaleinyuy45-sudo/Event-ticketing-and-ticket-hold-API-.git>
cd Events_Ticket_Inventory_1

Install the PHP dependencies:

composer install

Create the environment file:

cp .env.example .env

Generate the application key:

php artisan key:generate

Configure the database connection in ".env".

Run the migrations:

php artisan migrate

Start the development server:

php artisan serve

Running the Hold Expiration Scheduler

The application uses Laravel's scheduler to process expired holds.

For local development, run:

php artisan schedule:work

This keeps the scheduler running and allows the "ExpireHolds" command to run according to its configured schedule.

Project Structure

app/
├── Http/
│   ├── Controllers/
│   └── ...
├── Models/
├── Services/
└── ...

database/
├── migrations/
└── ...

routes/
└── api.php

tests/
├── Feature/
└── ...

Security

The API includes:

- Password hashing
- Authentication for protected routes
- Organizer authorization
- Request validation
- Database parameterization through Eloquent
- Hashed hold tokens

Organizers can only modify events that belong to them.

Row-Level Locking

The ticket type row is locked during inventory-changing transactions. This prevents concurrent requests from making decisions based on the same outdated inventory value.

Transactions

Inventory changes are performed inside database transactions so that related database operations either complete together or are rolled back.

Hold Expiration

Expired holds are removed from active inventory without deleting their records.

Thin Controllers

Controllers handle HTTP concerns while business logic is kept in service classes.
