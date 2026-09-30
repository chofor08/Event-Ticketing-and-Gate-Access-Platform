<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicEventController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        abort_unless($event->status === 'published', 404);

        return response()->json(['event' => $event->load('ticketTypes')]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Event::query()
            ->where('status', 'published')
            // Include sold and currently held tickets so the response can show remaining inventory.
            ->with(['ticketTypes' => function ($query): void {
                $query->withSum(['holds as confirmed_holds' => fn ($holds) => $holds->where('status', 'confirmed')], 'quantity')
                    ->withSum([
                        'holds as active_holds' => fn ($holds) => $holds->where('status', 'held')->where('expires_at', '>', now()),
                    ], 'quantity');
            }]);

        // Search by event name and filter by town.
        foreach (['name' => 'title', 'town' => 'town'] as $input => $column) {
            if ($request->filled($input)) {
                $query->where($column, 'like', '%'.$request->string($input).'%');
            }
        }

        // Filter by date range.
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        // Filter by discounted ticket price in xaf.
        if ($request->filled('min_price_xaf')) {
            $query->whereHas('ticketTypes', fn ($tickets) => $tickets->whereRaw(
                'ROUND(base_price_xaf * (100 - discount) / 100, 0) >= ?',
                [(int) $request->input('min_price_xaf')],
            ));
        }

        if ($request->filled('max_price_xaf')) {
            $query->whereHas('ticketTypes', fn ($tickets) => $tickets->whereRaw(
                'ROUND(base_price_xaf * (100 - discount) / 100, 0) <= ?',
                [(int) $request->input('max_price_xaf')],
            ));
        }

        $events = $query->latest('date')->paginate(20);
        $events->getCollection()->each(function (Event $event): void {
            $event->ticketTypes->each(function ($ticketType): void {
                $ticketType->remaining = max(0, $ticketType->quantity
                    - (int) ($ticketType->confirmed_holds ?? 0)
                    - (int) ($ticketType->active_holds ?? 0));
            });
        });

        return response()->json(['events' => $events]);
    }
}
