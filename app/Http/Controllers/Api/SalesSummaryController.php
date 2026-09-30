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

        $paidXaf = LedgerEntries::query()
            ->where('type', 'payment')
            ->whereIn('order_id', $scopedOrderIds)
            ->sum('amount_xaf');
        $refundXaf = abs((int) LedgerEntries::query()
            ->where('type', 'refund')
            ->whereIn('order_id', $scopedOrderIds)
            ->sum('amount_xaf'));

        return response()->json([
            'orders_count' => $orders->count(),
            'paid_xaf' => (int) $paidXaf,
            'refunded_xaf' => $refundXaf,
            'collected_xaf' => (int) $paidXaf - $refundXaf,
            'currency' => config('app.currency'),
            'refund_attribution' => 'full_order_per_organizer',
        ]);
    }
}
