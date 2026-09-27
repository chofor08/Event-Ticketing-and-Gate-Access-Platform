<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntries;
use App\Models\Orders;
use Illuminate\Http\JsonResponse;

class SalesSummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $organizerId = request()->user()->getKey();
        $orders = Orders::query()->whereHas(
            'orderItems.ticketType.event',
            fn ($events) => $events->where('organizer_id', $organizerId),
        );
        $scopedOrderIds = (clone $orders)->select('orders.id');

        $paidCents = LedgerEntries::query()
            ->where('type', 'payment')
            ->whereIn('order_id', $scopedOrderIds)
            ->sum('amount_cents');
        $refundCents = abs((int) LedgerEntries::query()
            ->where('type', 'refund')
            ->whereIn('order_id', $scopedOrderIds)
            ->sum('amount_cents'));

        return response()->json([
            'orders_count' => $orders->count(),
            'paid_cents' => (int) $paidCents,
            'refunded_cents' => $refundCents,
            'collected_cents' => (int) $paidCents - $refundCents,
            'currency' => 'usd',
            'refund_attribution' => 'full_order_per_organizer',
        ]);
    }
}
