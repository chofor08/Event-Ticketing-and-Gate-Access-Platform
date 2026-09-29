<?php

namespace App\Services;

use App\Models\Orders;
use App\Models\Ticket;
use App\Models\TicketIssuance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketIssuanceService
{
    public function __construct(private TicketCredentialService $credentialService) {}

    public function issue(Orders $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = Orders::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'paid'
                || TicketIssuance::query()->where('order_id', $order->getKey())->exists()) {
                return;
            }

            $order->loadMissing('orderItems.ticketType');
            TicketIssuance::create([
                'order_id' => $order->getKey(),
                'issued_at' => now(),
            ]);

            foreach ($order->orderItems as $orderItem) {
                $eventId = $orderItem->ticketType->event_id;

                for ($quantityIndex = 0; $quantityIndex < $orderItem->quantity; $quantityIndex++) {
                    $publicId = (string) Str::uuid();
                    Ticket::create([
                        'public_id' => $publicId,
                        'order_id' => $order->getKey(),
                        'order_item_id' => $orderItem->getKey(),
                        'ticket_type_id' => $orderItem->ticket_type_id,
                        'event_id' => $eventId,
                        'attendee_id' => $order->user_id,
                        'credential' => $this->credentialService->generate(
                            $publicId,
                            $eventId,
                            $order->user_id,
                        ),
                        'status' => 'issued',
                    ]);
                }
            }
        });
    }
}
