<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItems;
use App\Models\Orders;
use App\Services\CheckoutService;
use App\Services\OrderRefundService;
use App\Services\StripeGateway;
use App\Services\StripeWebhookService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;

class OrderManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Orders::query()
            ->where('user_id', $request->user()->getKey())
            ->with('orderItems.ticketType.event')
            ->latest()
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function orderItems(Request $request): JsonResponse
    {
        $orderItems = OrderItems::query()
            ->whereHas('order', fn ($orders) => $orders->where('user_id', $request->user()->getKey()))
            ->with('ticketType.event')
            ->latest()
            ->get()
            ->map(fn (OrderItems $orderItem): array => [
                'order_item' => [
                    'id' => $orderItem->id,
                    'ticket_type' => [
                        'id' => $orderItem->ticketType->id,
                        'name' => $orderItem->ticketType->name,
                        'event' => [
                            'id' => $orderItem->ticketType->event->id,
                            'title' => $orderItem->ticketType->event->title,
                        ],
                    ],
                    'quantity' => $orderItem->quantity,
                    'unit_price_cents' => $orderItem->unit_price_cents,
                    'sub_total_cents' => $orderItem->sub_total_cents,
                    'created_at' => $orderItem->created_at,
                    'updated_at' => $orderItem->updated_at,
                ],
            ]);

        return response()->json(['Order Details' => ['data' => $orderItems]]);
    }

    public function checkout(Request $request, CheckoutService $checkoutService): JsonResponse
    {
        $validated = $request->validate([
            'hold_ids' => ['required', 'array', 'min:1', 'max:20'],
            'hold_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        return response()->json($checkoutService->checkout($request->user(), $validated['hold_ids']), 201);
    }

    public function refund(Request $request, OrderRefundService $refundService): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1', 'exists:orders,id'],
        ]);
        $order = Orders::query()->findOrFail($validated['id']);

        if ((int) $order->user_id !== (int) $request->user()->getKey()) {
            throw new AuthorizationException('You may only refund your own orders.');
        }

        return response()->json(['order' => $refundService->requestFullRefund($order)]);
    }

    public function success(Request $request): JsonResponse
    {
        $request->validate(['session_id' => ['required', 'string']]);

        return response()->json([
            'message' => 'Checkout return received. Payment status is confirmed through the payment webhook.',
        ]);
    }

    public function cancel(): JsonResponse
    {
        return response()->json(['message' => 'Checkout was cancelled. Holds remain reserved until they expire.']);
    }

    public function webhook(Request $request, StripeGateway $stripe, StripeWebhookService $webhookService): JsonResponse
    {
        try {
            $event = $stripe->constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return response()->json(['message' => 'Invalid Stripe webhook signature.'], 400);
        }

        if (! $webhookService->process($event)) {
            return response()->json(['message' => 'Webhook processing is already in progress.'], 503);
        }

        return response()->json(['received' => true]);
    }
}
