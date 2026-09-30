<?php

namespace App\Services;

use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeGateway
{
    public function __construct(private StripeClient $stripe) {}

    public function createCheckoutSession(array $parameters, string $idempotencyKey): Session
    {
        return $this->stripe->checkout->sessions->create($parameters, ['idempotency_key' => $idempotencyKey]);
    }

    public function createRefund(string $paymentIntentId, int $amountXaf, string $idempotencyKey): Refund
    {
        return $this->stripe->refunds->create(
            ['payment_intent' => $paymentIntentId, 'amount' => $amountXaf],
            ['idempotency_key' => $idempotencyKey],
        );
    }

    public function retrieveCharge(string $chargeId): Charge
    {
        return $this->stripe->charges->retrieve($chargeId);
    }

    public function constructEvent(string $payload, string $signature, string $secret): Event
    {
        return Webhook::constructEvent($payload, $signature, $secret);
    }
}
