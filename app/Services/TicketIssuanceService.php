<?php

namespace App\Services;

use App\Models\Orders;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketIssuanceService
{
    public function __construct(private TicketCredentialService $credentialService) {}

    public function issue(Orders $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = Orders::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status !== 'paid') {
                return;
            }

            $lockedOrder->load('orderItems.ticketType.event');
            foreach ($lockedOrder->orderItems as $orderItem) {
                $eventId = (int) $orderItem->ticketType->event_id;
                for ($unitNumber = 1; $unitNumber <= $orderItem->quantity; $unitNumber++) {
                    $existing = Ticket::query()
                        ->where('order_item_id', $orderItem->getKey())
                        ->where('unit_number', $unitNumber)
                        ->exists();
                    if ($existing) {
                        continue;
                    }

                    $publicId = (string) Str::uuid();
                    $credential = $this->credentialService->generate(
                        $publicId,
                        $eventId,
                        (int) $lockedOrder->user_id,
                    );

                    Ticket::create([
                        'public_id' => $publicId,
                        'order_item_id' => $orderItem->getKey(),
                        'user_id' => $lockedOrder->user_id,
                        'event_id' => $eventId,
                        'unit_number' => $unitNumber,
                        'credential_key_id' => $credential['key_id'],
                        'code_hash' => $credential['code_hash'],
                        'status' => 'issued',
                    ]);
                }
            }
        });
    }
}
