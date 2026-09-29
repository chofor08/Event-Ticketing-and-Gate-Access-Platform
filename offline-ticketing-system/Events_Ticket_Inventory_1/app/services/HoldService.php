<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class HoldService
{
    public function createHold(int $ticketTypeId, int $quantity): array
    {

        return DB::transaction(function () use ($ticketTypeId, $quantity) {

            $ticketType = TicketType::where('id', $ticketTypeId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($ticketType->event->status === 'cancelled') {
                throw new RuntimeException(
                    'Cannot create a hold for a cancelled event.'
                );
            }

            $activeHeld = $ticketType->holds()
                ->where('status', 'held')
                ->where('expires_at', '>', now())
                ->sum('quantity');

            $confirmed = $ticketType->holds()
                ->where('status', 'confirmed')
                ->sum('quantity');

            $remaining = $ticketType->quantity
                - $activeHeld
                - $confirmed;

            if ($quantity > $remaining) {
                throw new RuntimeException(
                    'Not enough tickets available.'
                );
            }

            $token = Str::random(40);

            $hold = $ticketType->holds()->create([
                'quantity' => $quantity,
                'token' => Hash::make($token),
                'status' => 'held',
                'expires_at' => now()->addMinutes(10),
            ]);

            return [
                'hold' => $hold,
                'token' => $token,
            ];

        });
    }

    public function releaseHold(string $token, int $holdId)
    {
        return DB::transaction(function () use ($token, $holdId) {

            $hold = Hold::findOrFail($holdId);

            $hold->ticketType()
                ->lockForUpdate()
                ->firstOrFail();

            if (! Hash::check($token, $hold->token)) {
                throw new RuntimeException('Invalid hold token.');
            }

            if ($hold->status !== 'held') {
                throw new RuntimeException(
                    'This hold cannot be released because it is no longer active.'
                );
            }

            $hold->status = 'released';
            $hold->save();

            return $hold;
        });
    }

    public function confirmHold(string $token, int $holdId)
    {
        return DB::transaction(function () use ($token, $holdId) {

            $hold = Hold::findOrFail($holdId);

            $hold->ticketType()
                ->lockForUpdate()
                ->firstOrFail();

            if (! Hash::check($token, $hold->token)) {
                throw new RuntimeException('Invalid hold token.');
            }

            if ($hold->status !== 'held') {
                throw new RuntimeException(
                    'This hold is no longer active.'
                );
            }

            if ($hold->expires_at <= now()) {
                throw new RuntimeException(
                    'This hold has expired.'
                );
            }

            $hold->status = 'confirmed';
            $hold->save();

            return $hold;
        });
    }
}
