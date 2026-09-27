<?php

namespace App\Services;

use App\Events\OrderRefunded;
use App\Models\Event;
use App\Models\Hold;
use App\Models\LedgerEntries;
use App\Models\OrderItems;
use App\Models\Orders;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderRefundService
{
    public function __construct(private StripeGateway $stripe) {}

    public function requestFullRefund(Orders $order): Orders
    {
        $settledCents = abs((int) $order->ledgerEntries()->where('type', 'refund')->sum('amount_cents'));
        $remainingCents = max(0, $order->amount_cents - $settledCents);
        $settledItemIds = RefundRequest::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'succeeded')
            ->pluck('order_item_ids')
            ->flatten()
            ->all();
        $itemIds = $order->orderItems()
            ->whereNotIn('id', $settledItemIds ?: [0])
            ->pluck('id')
            ->all();

        if ($remainingCents === 0 || $itemIds === []) {
            return $order->refresh();
        }

        $key = 'order-'.$order->getKey().'-full-refund-'.$remainingCents;

        return $this->requestRefund($order, null, $itemIds, $remainingCents, 'attendee_request', $key);
    }

    public function requestEventRefund(Orders $order, Event $event): Orders
    {
        $settledItemIds = RefundRequest::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'succeeded')
            ->pluck('order_item_ids')
            ->flatten()
            ->all();
        $itemIds = $order->orderItems()
            ->whereNotIn('id', $settledItemIds ?: [0])
            ->whereHas('ticketType', fn ($query) => $query->where('event_id', $event->getKey()))
            ->pluck('id')
            ->all();

        if ($itemIds === []) {
            return $order->refresh();
        }

        $amountCents = (int) $order->orderItems()->whereIn('id', $itemIds)->sum('sub_total_cents');
        $key = 'order-'.$order->getKey().'-event-'.$event->getKey().'-refund';

        return $this->requestRefund($order, $event, $itemIds, $amountCents, 'event_cancelled', $key);
    }

    private function requestRefund(
        Orders $order,
        ?Event $event,
        array $itemIds,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): Orders {
        $request = DB::transaction(function () use ($order, $event, $itemIds, $amountCents, $reason, $idempotencyKey): RefundRequest {
            $order = Orders::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $existingRequest = RefundRequest::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existingRequest?->status === 'succeeded') {
                return $existingRequest;
            }

            if (! in_array($order->status, ['paid', 'partially_refunded', 'refund_failed', 'refund_pending'], true)
                || ! $order->payment_intent_id) {
                throw ValidationException::withMessages(['order' => 'Only paid orders can be refunded.']);
            }

            $settledCents = abs((int) $order->ledgerEntries()->where('type', 'refund')->sum('amount_cents'));
            $otherPendingCents = RefundRequest::query()
                ->where('order_id', $order->getKey())
                ->where('status', 'pending')
                ->where('idempotency_key', '!=', $idempotencyKey)
                ->sum('amount_cents');
            if ($amountCents < 1 || $amountCents > $order->amount_cents - $settledCents - $otherPendingCents) {
                throw ValidationException::withMessages(['order' => 'The refund amount exceeds the remaining paid balance.']);
            }

            // Keep a logical refund request per scope; attempts get unique Stripe keys on retry.
            $refundRequest = RefundRequest::query()->updateOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'order_id' => $order->getKey(),
                    'event_id' => $event?->getKey(),
                    'reason' => $reason,
                    'order_item_ids' => $itemIds,
                    'amount_cents' => $amountCents,
                    'attempts' => $existingRequest
                        ? $existingRequest->attempts + ($existingRequest->status === 'failed' ? 1 : 0)
                        : 1,
                    'stripe_refund_id' => $existingRequest?->status === 'failed'
                        ? null
                        : $existingRequest?->stripe_refund_id,
                    'status' => 'pending',
                    'last_error' => null,
                ],
            );

            $order->update(['status' => 'refund_pending']);

            return $refundRequest;
        });

        if ($request->status === 'succeeded') {
            return $order->refresh();
        }

        try {
            $refund = $this->stripe->createRefund(
                $order->payment_intent_id,
                $request->amount_cents,
                $request->idempotency_key.'-attempt-'.$request->attempts,
            );
        } catch (\Throwable $exception) {
            $request->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            $order->update(['status' => 'refund_failed']);
            throw $exception;
        }

        if ($refund->status === 'succeeded') {
            $this->settleRefundRequest($request, $refund->id, (int) $refund->amount);
        } else {
            $request->update([
                'stripe_refund_id' => $refund->id,
                'status' => in_array($refund->status, ['failed', 'canceled'], true) ? 'failed' : 'pending',
                'last_error' => $refund->failure_reason,
            ]);

            if ($request->status === 'failed') {
                $order->update(['status' => 'refund_failed']);
            }
        }

        return $order->refresh();
    }

    public function handleStripeRefundUpdate(
        string $stripeRefundId,
        string $status,
        int $amountCents,
        ?string $failureReason = null,
    ): void {
        $request = RefundRequest::query()->where('stripe_refund_id', $stripeRefundId)->first();
        if (! $request) {
            return;
        }

        if ($status === 'succeeded') {
            $this->settleRefundRequest($request, $stripeRefundId, $amountCents);

            return;
        }

        if (! in_array($status, ['failed', 'canceled'], true)) {
            return;
        }

        DB::transaction(function () use ($request, $failureReason): void {
            $request = RefundRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($request->status === 'succeeded') {
                return;
            }

            $request->update([
                'status' => 'failed',
                'last_error' => $failureReason,
            ]);

            $order = Orders::query()->whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $settledCents = abs((int) $order->ledgerEntries()->where('type', 'refund')->sum('amount_cents'));
            $otherPendingRefunds = RefundRequest::query()
                ->where('order_id', $order->getKey())
                ->where('status', 'pending')
                ->whereKeyNot($request->getKey())
                ->exists();

            $order->update([
                'status' => $otherPendingRefunds
                    ? 'refund_pending'
                    : ($settledCents > 0 ? 'partially_refunded' : 'refund_failed'),
            ]);
        });
    }

    public function settleRefundRequest(RefundRequest $request, string $stripeRefundId, int $amountRefundedCents): void
    {
        DB::transaction(function () use ($request, $stripeRefundId, $amountRefundedCents): void {
            $request = RefundRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($request->status === 'succeeded') {
                return;
            }

            $order = Orders::query()->whereKey($request->order_id)->lockForUpdate()->firstOrFail();
            $amount = min($amountRefundedCents, $request->amount_cents);
            $request->update([
                'status' => 'succeeded',
                'stripe_refund_id' => $stripeRefundId,
                'processed_at' => now(),
                'last_error' => null,
            ]);
            // Settle the ledger and release only the ticket lines covered by this refund.
            LedgerEntries::query()->firstOrCreate(
                ['reference_key' => $request->idempotency_key],
                [
                    'user_id' => $order->user_id,
                    'order_id' => $order->getKey(),
                    'type' => 'refund',
                    'amount_cents' => -$amount,
                    'payment' => 0,
                    'refund' => $amount / 100,
                ],
            );

            $holdIds = OrderItems::query()->whereIn('id', $request->order_item_ids)->pluck('hold_id');
            Hold::query()->whereIn('id', $holdIds)->where('status', 'confirmed')->update(['status' => 'released']);

            $totalRefunded = abs((int) $order->ledgerEntries()->where('type', 'refund')->sum('amount_cents'));
            $pendingRefunds = RefundRequest::query()
                ->where('order_id', $order->getKey())
                ->where('status', 'pending')
                ->whereKeyNot($request->getKey())
                ->exists();
            $order->update([
                'status' => $totalRefunded >= $order->amount_cents
                    ? 'refunded'
                    : ($pendingRefunds ? 'refund_pending' : 'partially_refunded'),
            ]);

            DB::afterCommit(fn () => OrderRefunded::dispatch($order->load('user'), $amount));
        });
    }

    public function reconcileStripeChargeRefund(Orders $order, string $eventId, int $totalRefundedCents): void
    {
        DB::transaction(function () use ($order, $eventId, $totalRefundedCents): void {
            $order = Orders::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $alreadyRefunded = abs((int) $order->ledgerEntries()->where('type', 'refund')->sum('amount_cents'));
            $amount = min(max(0, $totalRefundedCents - $alreadyRefunded), $order->amount_cents - $alreadyRefunded);
            if ($amount === 0) {
                return;
            }

            $key = 'stripe-charge-refund-event-'.$eventId;
            RefundRequest::query()->firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'order_id' => $order->getKey(),
                    'reason' => 'stripe_charge_refunded',
                    'order_item_ids' => [],
                    'amount_cents' => $amount,
                    'status' => 'succeeded',
                    'processed_at' => now(),
                ],
            );
            LedgerEntries::query()->firstOrCreate(
                ['reference_key' => $key],
                [
                    'user_id' => $order->user_id,
                    'order_id' => $order->getKey(),
                    'type' => 'refund',
                    'amount_cents' => -$amount,
                    'payment' => 0,
                    'refund' => $amount / 100,
                ],
            );

            $fullyRefunded = $alreadyRefunded + $amount >= $order->amount_cents;
            $order->update(['status' => $fullyRefunded ? 'refunded' : 'partially_refunded']);
            if ($fullyRefunded) {
                Hold::query()->whereIn('id', $order->orderItems()->pluck('hold_id'))
                    ->where('status', 'confirmed')->update(['status' => 'released']);
            }

            DB::afterCommit(fn () => OrderRefunded::dispatch($order->load('user'), $amount));
        });
    }
}
