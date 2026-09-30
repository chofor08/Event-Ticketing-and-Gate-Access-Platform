<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Hold;
use App\Models\OrderItems;
use App\Models\Orders;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiConnectionException;

class CheckoutService
{
    public function __construct(private StripeGateway $stripe) {}

    public function checkout(User $attendee, array $holdIds): array
    {
        $order = DB::transaction(function () use ($attendee, $holdIds): Orders {
            // Validate ownership before locking inventory or creating any order records.
            $holdReferences = Hold::query()
                ->where('user_id', $attendee->getKey())
                ->whereIn('id', $holdIds)
                ->get(['id', 'ticket_type_id']);

            if ($holdReferences->count() !== count($holdIds)) {
                throw ValidationException::withMessages(['hold_ids' => 'Every hold must belong to your account.']);
            }

            // Lock events, ticket types, then holds in stable order to avoid overselling.
            $ticketTypeReferences = TicketType::query()
                ->whereIn('id', $holdReferences->pluck('ticket_type_id')->unique())
                ->get(['id', 'event_id']);
            Event::query()
                ->whereIn('id', $ticketTypeReferences->pluck('event_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $ticketTypes = TicketType::query()
                ->whereIn('id', $ticketTypeReferences->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->with('event')
                ->get()
                ->keyBy('id');

            $holds = Hold::query()
                ->where('user_id', $attendee->getKey())
                ->whereIn('id', $holdIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($holds->count() !== count($holdIds)) {
                throw ValidationException::withMessages(['hold_ids' => 'Every hold must belong to your account.']);
            }

            // Reuse a pending order only when its complete hold set matches this retry.
            $requestedHoldIds = collect($holdIds)
                ->map(static fn ($holdId): int => (int) $holdId)
                ->sort()
                ->values()
                ->all();
            $pendingOrders = Orders::query()
                ->where('user_id', $attendee->getKey())
                ->where('status', 'pending')
                ->whereHas('orderItems', fn ($query) => $query->whereIn('hold_id', $holdIds))
                ->with('orderItems')
                ->lockForUpdate()
                ->get();
            $existingOrder = $pendingOrders->first(function (Orders $pendingOrder) use ($requestedHoldIds): bool {
                $orderHoldIds = $pendingOrder->orderItems
                    ->pluck('hold_id')
                    ->map(static fn ($holdId): int => (int) $holdId)
                    ->sort()
                    ->values()
                    ->all();

                return $orderHoldIds === $requestedHoldIds;
            });

            // Snapshot discounted prices in xaf before handing the order to Stripe.
            $totalXaf = 0;
            /** @var list<array{hold: Hold, ticket_type: TicketType, unit_price_xaf: int, sub_total_xaf: int}> $lines */
            $lines = [];

            foreach ($holds as $hold) {
                if ($hold->status !== 'held' || $hold->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        'hold_ids' => "Hold {$hold->id} is expired or no longer active.",
                    ]);
                }

                $holdOrderItem = $hold->orderItem;
                if ($holdOrderItem && (! $existingOrder || (int) $holdOrderItem->order_id !== (int) $existingOrder->getKey())) {
                    throw ValidationException::withMessages(['hold_ids' => "Hold {$hold->id} is already in an order."]);
                }

                $ticketType = $ticketTypes->get($hold->ticket_type_id);
                if (! $ticketType || $ticketType->event->status !== 'published' || ! $ticketType->event->startsAt()->isFuture()) {
                    throw ValidationException::withMessages([
                        'hold_ids' => "Hold {$hold->id} is no longer eligible for checkout.",
                    ]);
                }

                $unitPriceXaf = $ticketType->price_xaf;
                $subTotalXaf = $unitPriceXaf * $hold->quantity;
                $totalXaf += $subTotalXaf;
                $lines[] = [
                    'hold' => $hold,
                    'ticket_type' => $ticketType,
                    'unit_price_xaf' => $unitPriceXaf,
                    'sub_total_xaf' => $subTotalXaf,
                ];
            }

            if ($existingOrder) {
                $order = $existingOrder;
            } else {
                $order = Orders::create([
                    'user_id' => $attendee->getKey(),
                    'currency' => config('app.currency'),
                    'status' => 'pending',
                    'amount_xaf' => $totalXaf,
                ]);

                foreach ($lines as $line) {
                    /** @var TicketType $ticketType */
                    $ticketType = $line['ticket_type'];
                    // Keep the charged price on the order line even if the ticket price changes later.
                    OrderItems::create([
                        'order_id' => $order->getKey(),
                        'ticket_type_id' => $ticketType->getKey(),
                        'hold_id' => $line['hold']->getKey(),
                        'quantity' => $line['hold']->quantity,
                        'unit_price_xaf' => $line['unit_price_xaf'],
                        'sub_total_xaf' => $line['sub_total_xaf'],
                    ]);
                }
            }

            return $order;
        });

        try {
            // Create the payment session only after every hold and order line has been validated and stored.
            $order->load('orderItems.ticketType.event');
            $lineItems = $order->orderItems->map(fn (OrderItems $line): array => [
                'price_data' => [
                    'currency' => $order->currency,
                    'product_data' => [
                        'name' => $line->ticketType->event->title.' - '.$line->ticketType->name,
                    ],
                    'unit_amount' => $line->unit_price_xaf,
                ],
                'quantity' => $line->quantity,
            ])->all();

            $session = $this->stripe->createCheckoutSession([
                'line_items' => $lineItems,
                'metadata' => [
                    'order_id' => (string) $order->getKey(),
                    'user_id' => (string) $attendee->getKey(),
                ],
                'mode' => 'payment',
                'payment_intent_data' => [
                    'metadata' => ['order_id' => (string) $order->getKey()],
                ],
                'success_url' => route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('checkout.cancel'),
                'expires_at' => now()->addMinutes(30)->timestamp,
            ], 'checkout-order-'.$order->getKey());

            $order->update(['session_id' => $session->id]);
        } catch (ApiConnectionException $exception) {
            // Keep the pending order and holds so the attendee can retry after reconnecting.
            throw $exception;
        } catch (\Throwable $exception) {
            // If Stripe session creation fails, release holds still owned by this pending order.
            DB::transaction(function () use ($order): void {
                $lockedOrder = Orders::query()->whereKey($order->getKey())->lockForUpdate()->first();
                if (! $lockedOrder || $lockedOrder->status !== 'pending') {
                    return;
                }

                $lockedOrder->update(['status' => 'failed']);
                Hold::query()
                    ->whereIn('id', $lockedOrder->orderItems()->pluck('hold_id'))
                    ->where('status', 'held')
                    ->update(['status' => 'released']);
            });

            throw $exception;
        }

        return [
            'order' => $order->refresh(),
            'checkout_url' => $session->url,
        ];
    }
}
