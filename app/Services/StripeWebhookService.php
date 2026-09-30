<?php

namespace App\Services;

use App\Events\OrderPaid;
use App\Events\OrderSettled;
use App\Models\Event;
use App\Models\Hold;
use App\Models\LedgerEntries;
use App\Models\Orders;
use App\Models\StripeWebhookEvent;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\Event as StripeEvent;

class StripeWebhookService
{
    public function __construct(private OrderRefundService $refundService, private StripeGateway $stripe) {}

    public function process(StripeEvent $stripeEvent): bool
    {
        // Claim each Stripe event atomically so retries cannot apply ledger or inventory changes twice.
        $claim = DB::transaction(function () use ($stripeEvent): string {
            $record = StripeWebhookEvent::query()->firstOrCreate(
                ['stripe_event_id' => $stripeEvent->id],
                ['type' => $stripeEvent->type, 'status' => 'received'],
            );
            $record = StripeWebhookEvent::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($record->processed_at) {
                return 'processed';
            }

            if ($record->status === 'processing' && $record->updated_at?->isAfter(now()->subMinutes(5))) {
                return 'busy';
            }

            $record->update([
                'type' => $stripeEvent->type,
                'status' => 'processing',
                'attempts' => $record->attempts + 1,
                'last_error' => null,
            ]);

            return 'claimed';
        });

        if ($claim === 'busy') {
            return false;
        }

        if ($claim === 'processed') {
            return true;
        }

        try {
            $refundOrderId = $this->dispatchEvent($stripeEvent);
            if ($refundOrderId !== null) {
                $order = Orders::query()->findOrFail($refundOrderId);
                $this->refundService->requestFullRefund($order);
            }

            StripeWebhookEvent::query()
                ->where('stripe_event_id', $stripeEvent->id)
                ->update(['status' => 'processed', 'processed_at' => now()]);

            return true;
        } catch (\Throwable $exception) {
            StripeWebhookEvent::query()
                ->where('stripe_event_id', $stripeEvent->id)
                ->update([
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);

            throw $exception;
        }
    }

    private function dispatchEvent(StripeEvent $stripeEvent): ?int
    {
        $object = $stripeEvent->data->object;

        // Stripe's webhook is authoritative for payment, expiry, and refund state transitions.
        return match ($stripeEvent->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->completeSession($object),
            'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->expireSession($object),
            'payment_intent.succeeded' => $this->updatePaymentIntent($object),
            'charge.updated' => $this->updateCharge($object),
            'charge.refunded' => $this->settleChargeRefund($stripeEvent, $object),
            'refund.updated' => $this->updateRefund($object),
            default => null,
        };
    }

    private function updateRefund(object $refund): ?int
    {
        $this->refundService->handleStripeRefundUpdate(
            (string) $refund->id,
            (string) $refund->status,
            (int) $refund->amount,
            isset($refund->failure_reason) ? (string) $refund->failure_reason : null,
        );

        return null;
    }

    private function updatePaymentIntent(object $paymentIntent): ?int
    {
        $paymentIntentId = (string) ($paymentIntent->id ?? '');
        if ($paymentIntentId === '') {
            return null;
        }

        $order = Orders::query()->where('payment_intent_id', $paymentIntentId)->first();
        if (! $order) {
            $orderId = (int) ($paymentIntent->metadata->order_id ?? 0);
            $order = $orderId > 0 ? Orders::query()->find($orderId) : null;
        }

        if (! $order || ($order->payment_intent_id && $order->payment_intent_id !== $paymentIntentId)) {
            return null;
        }

        $charge = $paymentIntent->latest_charge ?? null;
        if (is_string($charge) && $charge !== '') {
            $charge = $this->stripe->retrieveCharge($charge);
        }

        return is_object($charge) ? $this->updateCharge($charge, $order) : null;
    }

    private function updateCharge(object $charge, ?Orders $order = null): ?int
    {
        $paymentIntentId = (string) ($charge->payment_intent ?? $order?->payment_intent_id ?? '');
        if ($paymentIntentId === '') {
            return null;
        }

        $order ??= Orders::query()->where('payment_intent_id', $paymentIntentId)->first();
        if (! $order || ($order->payment_intent_id && $order->payment_intent_id !== $paymentIntentId)) {
            return null;
        }

        $paymentMethodDetails = $charge->payment_method_details ?? null;
        $paymentMethodType = isset($paymentMethodDetails->type) ? (string) $paymentMethodDetails->type : null;
        $card = $paymentMethodDetails->card ?? null;
        $bankAccount = $paymentMethodDetails->us_bank_account ?? null;
        $brand = isset($card->brand) ? (string) $card->brand : null;
        $last4 = isset($card->last4)
            ? (string) $card->last4
            : (isset($bankAccount->last4) ? (string) $bankAccount->last4 : null);
        $safeDetails = array_filter([
            'type' => $paymentMethodType,
            'funding' => isset($card->funding) ? (string) $card->funding : null,
            'bank_name' => isset($bankAccount->bank_name) ? (string) $bankAccount->bank_name : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        DB::transaction(function () use ($order, $charge, $paymentIntentId, $paymentMethodType, $brand, $last4, $safeDetails): void {
            $order = Orders::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $shouldDispatchConfirmation = $order->status === 'paid'
                && ! $order->payment_method_type
                && $paymentMethodType !== null;
            $order->update([
                'payment_intent_id' => $paymentIntentId,
                'currency' => strtolower((string) $charge->currency),
                'payment_method_type' => $paymentMethodType,
                'payment_method_id' => isset($charge->payment_method) ? (string) $charge->payment_method : null,
                'payment_method_brand' => $brand,
                'payment_method_last4' => $last4,
                'payment_method_details' => $safeDetails ?: null,
            ]);

            if ($shouldDispatchConfirmation) {
                DB::afterCommit(fn () => OrderPaid::dispatch($order->load('user', 'orderItems.ticketType.event')));
            }
        });

        return null;
    }

    private function completeSession(object $session): ?int
    {
        $orderId = (int) ($session->metadata->order_id ?? 0);
        if ($orderId < 1) {
            throw new RuntimeException('Stripe Checkout session has no order metadata.');
        }

        return DB::transaction(function () use ($session, $orderId): ?int {
            $order = Orders::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (in_array($order->status, ['paid', 'refund_pending', 'refunded'], true)) {
                return null;
            }

            $order->update([
                'session_id' => $session->id,
                'payment_intent_id' => $session->payment_intent,
            ]);

            if (($session->payment_status ?? null) !== 'paid') {
                return null;
            }

            // Confirm the complete cart only after verifying every hold and the Stripe total.
            $items = $order->orderItems()->with('hold', 'ticketType.event')->get();
            $events = $items->map(fn ($item) => $item->ticketType?->event_id)->filter()->unique()->sort();
            Event::query()->whereIn('id', $events)->orderBy('id')->lockForUpdate()->get(['id']);
            $ticketTypeIds = $items->pluck('ticket_type_id')->unique()->sort()->values();
            $ticketTypes = TicketType::query()->whereIn('id', $ticketTypeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $holdIds = $items->pluck('hold_id')->sort()->values();
            $holds = Hold::query()->whereIn('id', $holdIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $holdsAreValid = $items->isNotEmpty()
                && (int) ($session->metadata->user_id ?? 0) === (int) $order->user_id
                && (int) ($session->amount_total ?? -1) === (int) $order->amount_xaf
                && strtolower((string) ($session->currency ?? '')) === strtolower($order->currency);
            foreach ($items as $item) {
                $hold = $holds->get($item->hold_id);
                $ticketType = $ticketTypes->get($item->ticket_type_id);

                if (! $hold || ! $ticketType || (int) $hold->user_id !== (int) $order->user_id
                    || (int) $hold->ticket_type_id !== (int) $item->ticket_type_id
                    || $hold->status !== 'held' || $hold->expires_at->isPast()
                    || $ticketType->event->status !== 'published' || ! $ticketType->event->startsAt()->isFuture()) {
                    $holdsAreValid = false;
                    break;
                }
            }

            if (! $holdsAreValid) {
                // Inventory may have been released; never confirm stale holds after a late payment.
                $order->update(['status' => 'refund_pending']);
                foreach ($holds as $hold) {
                    if ($hold->status === 'held') {
                        $hold->update(['status' => $hold->expires_at->isPast() ? 'expired' : 'released']);
                    }
                }

                return $order->getKey();
            }

            foreach ($holds as $hold) {
                $hold->update(['status' => 'confirmed']);
            }

            // Record payment once, keyed by order, after all ticket holds are confirmed.
            $order->update(['status' => 'paid']);
            LedgerEntries::query()->firstOrCreate(
                ['reference_key' => 'order-'.$order->getKey().'-payment'],
                [
                    'user_id' => $order->user_id,
                    'order_id' => $order->getKey(),
                    'type' => 'payment',
                    'amount_xaf' => $order->amount_xaf,
                ],
            );

            DB::afterCommit(fn () => OrderSettled::dispatch($order->load('user', 'orderItems.ticketType.event')));

            if ($order->payment_method_type) {
                DB::afterCommit(fn () => OrderPaid::dispatch($order->load('user', 'orderItems.ticketType.event')));
            }

            return null;
        });
    }

    private function expireSession(object $session): ?int
    {
        $orderId = (int) ($session->metadata->order_id ?? 0);
        if ($orderId < 1) {
            return null;
        }

        DB::transaction(function () use ($orderId, $session): void {
            $order = Orders::query()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'pending') {
                return;
            }

            $order->update(['status' => 'expired', 'session_id' => $session->id]);
            // Release the order's unconfirmed holds when Stripe closes the checkout session.
            Hold::query()
                ->whereIn('id', $order->orderItems()->pluck('hold_id'))
                ->where('status', 'held')
                ->update(['status' => 'released']);
        });

        return null;
    }

    private function settleChargeRefund(StripeEvent $stripeEvent, object $charge): ?int
    {
        $order = Orders::query()->where('payment_intent_id', $charge->payment_intent)->first();
        if (! $order) {
            return null;
        }

        foreach ($charge->refunds->data ?? [] as $refund) {
            $this->refundService->handleStripeRefundUpdate(
                (string) $refund->id,
                (string) $refund->status,
                (int) $refund->amount,
                isset($refund->failure_reason) ? (string) $refund->failure_reason : null,
            );
        }

        $this->refundService->reconcileStripeChargeRefund(
            $order,
            $stripeEvent->id,
            (int) $charge->amount_refunded,
        );

        return null;
    }
}
