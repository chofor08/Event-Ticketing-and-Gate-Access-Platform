<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Hold;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HoldService
{
    /** @return array{hold: Hold, token: string} */
    public function create(TicketType $ticketType, User $attendee, int $quantity): array
    {
        return DB::transaction(function () use ($ticketType, $attendee, $quantity): array {
            $eventId = TicketType::query()->whereKey($ticketType->getKey())->value('event_id');
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            $ticketType = TicketType::query()->whereKey($ticketType->getKey())->lockForUpdate()->firstOrFail();

            if ($event->status !== 'published' || ! $event->startsAt()->isFuture()) {
                throw ValidationException::withMessages([
                    'ticket_type' => 'Tickets can only be held for published future events.',
                ]);
            }

            $heldQuantity = $ticketType->holds()
                ->where('status', 'held')
                ->where('expires_at', '>', now())
                ->sum('quantity');
            $confirmedQuantity = $ticketType->holds()->where('status', 'confirmed')->sum('quantity');
            $remainingQuantity = $ticketType->quantity - $heldQuantity - $confirmedQuantity;

            if ($quantity > $remainingQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Not enough tickets are available.',
                ]);
            }

            $token = Str::random(64);
            $hold = $ticketType->holds()->create([
                'user_id' => $attendee->getKey(),
                'quantity' => $quantity,
                'status' => 'held',
                'token_hash' => Hash::make($token),
                'expires_at' => now()->addMinutes(10),
            ]);

            return ['hold' => $hold, 'token' => $token];
        });
    }

    public function release(Hold $hold, User $attendee, string $token): void
    {
        DB::transaction(function () use ($hold, $attendee, $token): void {
            $hold = Hold::query()->whereKey($hold->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $hold->user_id !== (int) $attendee->getKey()) {
                throw new AuthorizationException('This hold does not belong to your account.');
            }

            if (! Hash::check($token, $hold->token_hash)) {
                throw new AuthorizationException('Invalid hold token.');
            }

            if ($hold->status !== 'held') {
                throw ValidationException::withMessages(['hold' => 'This hold is no longer active.']);
            }

            if ($hold->orderItem()->whereHas('order', fn ($query) => $query->whereIn('status', ['pending', 'paid', 'refund_pending']))->exists()) {
                throw ValidationException::withMessages(['hold' => 'A hold attached to an order cannot be released directly.']);
            }

            $hold->update(['status' => $hold->expires_at->isPast() ? 'expired' : 'released']);
        });
    }
}
