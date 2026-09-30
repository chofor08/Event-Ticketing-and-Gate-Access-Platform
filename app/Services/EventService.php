<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Orders;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class EventService
{
    public function createEvent(User $organizer, array $attributes): Event
    {
        return $organizer->events()->create($attributes);
    }

    public function updateEvent(Event $event, User $organizer, array $attributes): Event
    {
        $this->authorizeOwner($event, $organizer);
        $this->ensureNotCancelled($event);
        $event->update($attributes);

        return $event->refresh();
    }

    public function publishEvent(Event $event, User $organizer): Event
    {
        return DB::transaction(function () use ($event, $organizer): Event {
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($event, $organizer);

            if ($event->status !== 'draft') {
                throw ValidationException::withMessages([
                    'event' => 'Only draft events can be published.',
                ]);
            }

            $event->update(['status' => 'published']);

            return $event->refresh();
        });
    }

    public function cancelEvent(Event $event, User $organizer, OrderRefundService $refundService): array
    {
        [$event, $orders] = DB::transaction(function () use ($event, $organizer): array {
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($event, $organizer);

            if ($event->status !== 'cancelled') {
                $event->ticketTypes()->each(function (TicketType $ticketType): void {
                    $ticketType->holds()
                        ->where('status', 'held')
                        ->where('expires_at', '>', now())
                        ->update(['status' => 'released']);
                });

                $event->update(['status' => 'cancelled']);
            }

            $orders = Orders::query()
                ->whereIn('status', ['paid', 'partially_refunded', 'refund_failed', 'refund_pending'])
                ->whereHas('orderItems.ticketType', fn ($query) => $query->where('event_id', $event->getKey()))
                ->get();

            return [$event->refresh(), $orders];
        });

        $refundResults = [];
        foreach ($orders as $order) {
            try {
                $refundResults[$order->getKey()] = $refundService->requestEventRefund($order, $event)->status;
            } catch (Throwable) {
                $refundResults[$order->getKey()] = 'refund_failed';
            }
        }

        return ['event' => $event, 'refunds' => $refundResults];
    }

    public function ensureOwner(Event $event, User $organizer): void
    {
        $this->authorizeOwner($event, $organizer);
    }

    private function authorizeOwner(Event $event, User $organizer): void
    {
        if ((int) $event->organizer_id !== (int) $organizer->getKey()) {
            throw new AuthorizationException('You do not own this event.');
        }
    }

    private function ensureNotCancelled(Event $event): void
    {
        if ($event->status === 'cancelled') {
            throw ValidationException::withMessages(['event' => 'A cancelled event cannot be changed.']);
        }
    }
}
