<?php

namespace App\Listeners;

use App\Events\OrderRefunded;
use App\Mail\RefundConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class OrderRefundedConfirmation implements ShouldQueue
{
    public function handle(OrderRefunded $event): void
    {
        Mail::to($event->order->user)->send(new RefundConfirmed($event->order, $event->amountXaf));
    }
}
