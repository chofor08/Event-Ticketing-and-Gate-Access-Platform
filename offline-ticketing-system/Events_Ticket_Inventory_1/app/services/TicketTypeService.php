<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketTypeService
{
    public function listForEvent(Event $event, int $organizerId): Collection
    {
        $this->ensureOrganizerOwnsEvent($event, $organizerId);
        $this->ensureEventIsNotCancelled($event);

        return $event->ticketTypes;
    }

    public function createTicketType(Event $event, int $organizerId, array $validated): TicketType
    {
        $this->ensureOrganizerOwnsEvent($event, $organizerId);
        $this->ensureEventIsNotCancelled($event);

        $attributes = $this->mapPriceFields($validated);
        $attributes['discount'] = $attributes['discount'] ?? 0;

        return $event->ticketTypes()->create($attributes);
    }

    public function updateTicketType(TicketType $ticketType, int $organizerId, array $validated): TicketType
    {
        return DB::transaction(function () use ($ticketType, $organizerId, $validated): TicketType {
            $ticketType = TicketType::query()
                ->whereKey($ticketType->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureOrganizerOwnsEvent($ticketType->event, $organizerId);
            $this->ensureEventIsNotCancelled($ticketType->event);

            $committed = $ticketType->holds()
                ->where(function ($query) {
                    $query->where('status', 'confirmed')
                        ->orWhere(function ($query) {
                            $query->where('status', 'held')
                                ->where('expires_at', '>', now());
                        });
                })
                ->sum('quantity');

            if (isset($validated['quantity']) && $validated['quantity'] < $committed) {
                throw ValidationException::withMessages([
                    'quantity' => "Quantity cannot be lower than the number of tickets held or sold ({$committed}).",
                ]);
            }

            $ticketType->update($this->mapPriceFields($validated));

            return $ticketType->refresh();
        });
    }

    public function deleteTicketType(TicketType $ticketType, int $organizerId): void
    {
        DB::transaction(function () use ($ticketType, $organizerId): void {
            $ticketType = TicketType::query()
                ->whereKey($ticketType->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureOrganizerOwnsEvent($ticketType->event, $organizerId);
            $this->ensureEventIsNotCancelled($ticketType->event);

            $hasCommittedHolds = $ticketType->holds()
                ->where(function ($query) {
                    $query->where('status', 'confirmed')
                        ->orWhere(function ($query) {
                            $query->where('status', 'held')
                                ->where('expires_at', '>', now());
                        });
                })
                ->exists();

            if ($hasCommittedHolds) {
                throw ValidationException::withMessages([
                    'ticket_type' => 'Cannot delete a ticket type with active holds or confirmed tickets.',
                ]);
            }

            $ticketType->delete();
        });
    }

    public function inventoryForEvent(Event $event, int $organizerId): Collection
    {
        $this->ensureOrganizerOwnsEvent($event, $organizerId);

        return $event->ticketTypes()
            ->withSum([
                'holds as sold' => fn ($query) => $query->where('status', 'confirmed'),
            ], 'quantity')
            ->withSum([
                'holds as held' => fn ($query) => $query->where('status', 'held')
                    ->where('expires_at', '>', now()),
            ], 'quantity')
            ->get()
            ->each(function (TicketType $ticketType): void {
                $ticketType->sold = $ticketType->sold ?? 0;
                $ticketType->held = $ticketType->held ?? 0;
                $ticketType->remaining = $ticketType->quantity - $ticketType->sold - $ticketType->held;
            });
    }

    private function ensureOrganizerOwnsEvent(Event $event, int $organizerId): void
    {
        if ($event->organizer_id !== $organizerId) {
            throw new AuthorizationException('Unauthorized');
        }
    }

    private function ensureEventIsNotCancelled(Event $event): void
    {
        if ($event->status === 'cancelled') {
            throw ValidationException::withMessages([
                'event' => 'Ticket types cannot be changed for a cancelled event.',
            ]);
        }
    }

    private function mapPriceFields(array $attributes): array
    {
        if (array_key_exists('price', $attributes)) {
            $attributes['base_price'] = $attributes['price'];
            unset($attributes['price']);
        }

        if (array_key_exists('discount', $attributes)) {
            $attributes['discount'] = $attributes['discount'] ?? 0;
        }

        return $attributes;
    }
}
