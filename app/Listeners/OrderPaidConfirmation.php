<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Mail\PaymentConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class OrderPaidConfirmation implements ShouldQueue
{
    public function handle(OrderPaid $event): void
    {
        Mail::to($event->order->user)->send(new PaymentConfirmed($event->order));
    }
}
