<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\TicketIssuanceService;

class IssueTickets
{
    /**
     * Create the event listener.
     */
    public function __construct(private TicketIssuanceService $ticketIssuanceService)
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderPaid $event): void
    {
        $this->ticketIssuanceService->issue($event->order);
    }
}
