<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketTypeService
{
    public function listForEvent(Event $event, User $organizer): Collection
    {
        $this->authorizeManage($event, $organizer);

        return $event->ticketTypes;
    }

    public function create(Event $event, User $organizer, array $attributes): TicketType
    {
        $this->authorizeManage($event, $organizer);

        return $event->ticketTypes()->create($attributes);
    }

    public function update(TicketType $ticketType, User $organizer, array $attributes): TicketType
    {
        return DB::transaction(function () use ($ticketType, $organizer, $attributes): TicketType {
            $ticketType = TicketType::query()->whereKey($ticketType->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeManage($ticketType->event, $organizer);

            $committedQuantity = $ticketType->holds()
                ->where(function ($query): void {
                    $query->where('status', 'confirmed')
                        ->orWhere(function ($query): void {
                            $query->where('status', 'held')->where('expires_at', '>', now());
                        });
                })
                ->sum('quantity');

            if (isset($attributes['quantity']) && $attributes['quantity'] < $committedQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Quantity cannot be less than the active and sold quantity ({$committedQuantity}).",
                ]);
            }

            $ticketType->update($attributes);

            return $ticketType->refresh();
        });
    }

    public function delete(TicketType $ticketType, User $organizer): void
    {
        DB::transaction(function () use ($ticketType, $organizer): void {
            $ticketType = TicketType::query()->whereKey($ticketType->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeManage($ticketType->event, $organizer);

            if ($ticketType->holds()->exists() || $ticketType->orderItems()->exists()) {
                throw ValidationException::withMessages([
                    'ticket_type' => 'A ticket type with hold or order history cannot be deleted.',
                ]);
            }

            $ticketType->delete();
        });
    }

    public function inventory(Event $event, User $organizer): Collection
    {
        $this->authorizeOwner($event, $organizer);

        return $event->ticketTypes()
            ->withSum(['holds as sold' => fn ($query) => $query->where('status', 'confirmed')], 'quantity')
            ->withSum([
                'holds as held' => fn ($query) => $query->where('status', 'held')->where('expires_at', '>', now()),
            ], 'quantity')
            ->get()
            ->each(function (TicketType $ticketType): void {
                $ticketType->sold = (int) ($ticketType->sold ?? 0);
                $ticketType->held = (int) ($ticketType->held ?? 0);
                $ticketType->remaining = $ticketType->quantity - $ticketType->sold - $ticketType->held;
            });
    }

    private function authorizeManage(Event $event, User $organizer): void
    {
        $this->authorizeOwner($event, $organizer);

        if ($event->status === 'cancelled') {
            throw ValidationException::withMessages(['event' => 'A cancelled event cannot be changed.']);
        }
    }

    private function authorizeOwner(Event $event, User $organizer): void
    {
        if ((int) $event->organizer_id !== (int) $organizer->getKey()) {
            throw new AuthorizationException('You do not own this event.');
        }
    }
}
