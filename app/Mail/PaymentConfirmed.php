<?php

namespace App\Mail;

use App\Models\Orders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Orders $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment confirmation for order #'.$this->order->id);
    }

    public function content(): Content
    {
        $this->order->loadMissing('user', 'orderItems.ticketType.event');

        return new Content(
            view: 'emails.payment-confirmed',
            with: [
                'order' => $this->order,
                'userName' => $this->order->user->name,
                'amount' => number_format($this->order->amount_xaf, 0),
                'currency' => strtoupper($this->order->currency),
                'orderId' => $this->order->id,
                'txnId' => $this->order->payment_intent_id,
                'date' => $this->order->updated_at->format('F j, Y, g:i a'),
                'method' => $this->order->payment_method_display,
                'items' => $this->order->orderItems->map(fn ($item): object => (object) [
                    'name' => $item->ticketType->event->title.' - '.$item->ticketType->name,
                    'quantity' => $item->quantity,
                    'price' => $item->unit_price_xaf,
                ]),
            ],
        );
    }
}
