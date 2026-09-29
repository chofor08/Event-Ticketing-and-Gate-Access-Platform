<?php

namespace App\Events;

use App\Models\Orders;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderSettled
{
    use Dispatchable, SerializesModels;

    public function __construct(public Orders $order) {}

    /** @return array{event: string, order_id: int, user: array{id: int, email: string}, items: array<int, array{product_id: int, ticket_type_id: int, order_item_id: int, event_id: int, quantity: int, unit_price_cents: int}>, total_cents: int, currency: string} */
    public function toModuleCPayload(): array
    {
        $this->order->loadMissing('user', 'orderItems.ticketType');

        return [
            'event' => 'order.paid',
            'order_id' => (int) $this->order->getKey(),
            'user' => [
                'id' => (int) $this->order->user_id,
                'email' => $this->order->user->email,
            ],
            'items' => $this->order->orderItems->map(fn ($item): array => [
                'product_id' => (int) $item->ticket_type_id,
                'ticket_type_id' => (int) $item->ticket_type_id,
                'order_item_id' => (int) $item->getKey(),
                'event_id' => (int) $item->ticketType->event_id,
                'quantity' => (int) $item->quantity,
                'unit_price_cents' => (int) $item->unit_price_cents,
            ])->all(),
            'total_cents' => (int) $this->order->amount_cents,
            'currency' => (string) $this->order->currency,
        ];
    }
}
