<?php

use App\Models\Event;
use App\Models\Hold;
use App\Models\TicketType;
use App\Models\User;
use App\Services\EventService;
use App\Services\TicketTypeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createOrganizer(): User
{
    static $sequence = 0;
    $sequence++;

    return User::create([
        'name' => 'Organizer '.$sequence,
        'email' => 'organizer'.$sequence.'@example.test',
        'password' => 'password',
        'role' => 'organizer',
    ]);
}

function createEventFor(User $organizer, string $status = 'published'): Event
{
    return Event::create([
        'organizer_id' => $organizer->id,
        'title' => 'Community Concert',
        'status' => $status,
        'venue' => 'Town Hall',
        'town' => 'Bamenda',
        'description' => 'A community event',
        'date' => now()->addWeek()->toDateString(),
        'start_time' => '18:00:00',
    ]);
}

test('event service returns the updated event model', function () {
    $organizer = createOrganizer();
    $event = createEventFor($organizer);

    $updated = app(EventService::class)->updateEvent($event, $organizer->id, [
        'title' => 'Updated Concert',
    ]);

    expect($updated)->toBeInstanceOf(Event::class)
        ->and($updated->title)->toBe('Updated Concert');
});

test('ticket type service creates, updates, and calculates inventory', function () {
    $organizer = createOrganizer();
    $event = createEventFor($organizer);
    $service = app(TicketTypeService::class);
    $ticketType = $service->createTicketType($event, $organizer->id, [
        'name' => 'General Admission',
        'price' => 25,
        'discount' => 20,
        'quantity' => 10,
    ]);

    $updated = $service->updateTicketType($ticketType, $organizer->id, ['price' => 30]);
    $inventory = $service->inventoryForEvent($event, $organizer->id)->first();

    expect($updated)->toBeInstanceOf(TicketType::class)
        ->and($updated->base_price)->toBe(30)
        ->and($updated->discount)->toBe(20)
        ->and($updated->price)->toBe(24)
        ->and($updated->toArray()['price'])->toBe(24)
        ->and($inventory->remaining)->toBe(10)
        ->and($service->listForEvent($event, $organizer->id))->toHaveCount(1);
});

test('ticket discounts default to zero and calculate full and rounded discounts', function () {
    $organizer = createOrganizer();
    $event = createEventFor($organizer);
    $service = app(TicketTypeService::class);

    $undiscounted = $service->createTicketType($event, $organizer->id, [
        'name' => 'Standard',
        'price' => 101,
        'quantity' => 10,
    ]);
    $fullyDiscounted = $service->createTicketType($event, $organizer->id, [
        'name' => 'Free Entry',
        'price' => 101,
        'discount' => 100,
        'quantity' => 10,
    ]);

    expect($undiscounted->discount)->toBe(0)
        ->and($undiscounted->price)->toBe(101)
        ->and($fullyDiscounted->price)->toBe(0)
        ->and($service->updateTicketType($undiscounted, $organizer->id, ['discount' => 10])->price)->toBe(91)
        ->and($service->updateTicketType($undiscounted, $organizer->id, ['discount' => null])->discount)->toBe(0);
});

test('ticket type service prevents reducing quantity below confirmed tickets', function () {
    $organizer = createOrganizer();
    $event = createEventFor($organizer);
    $ticketType = TicketType::create([
        'event_id' => $event->id,
        'name' => 'General Admission',
        'base_price' => 25,
        'discount' => 0,
        'quantity' => 10,
    ]);

    Hold::create([
        'ticket_type_id' => $ticketType->id,
        'quantity' => 4,
        'status' => 'confirmed',
        'token' => 'hashed-token',
        'expires_at' => now()->addMinutes(10),
    ]);

    expect(fn () => app(TicketTypeService::class)->updateTicketType(
        $ticketType,
        $organizer->id,
        ['quantity' => 3],
    ))->toThrow(ValidationException::class);
});

test('ticket type service rejects another organizer', function () {
    $organizer = createOrganizer();
    $anotherOrganizer = createOrganizer();
    $event = createEventFor($organizer);

    expect(fn () => app(TicketTypeService::class)->listForEvent($event, $anotherOrganizer->id))
        ->toThrow(AuthorizationException::class);
});
