<?php

namespace App\Events;

use App\Models\Orders;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(public Orders $order, public int $amountCents) {}
}
