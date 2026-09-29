<?php

namespace App\Listeners;

use App\Events\OrderSettled;
use App\Models\Orders;
use App\Services\TicketIssuanceService;
use Illuminate\Contracts\Queue\ShouldQueue;

class IssueSettledTickets implements ShouldQueue
{
    public function __construct(private TicketIssuanceService $ticketIssuanceService) {}

    public function handle(OrderSettled $event): void
    {
        $payload = $event->toModuleCPayload();
        $this->ticketIssuanceService->issue(Orders::query()->findOrFail($payload['order_id']));
    }
}
