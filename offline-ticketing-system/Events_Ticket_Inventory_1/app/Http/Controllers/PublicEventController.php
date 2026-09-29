<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;

class PublicEventController extends Controller
{
    public function index(Request $request)
    {
        $query = Event::query()
            ->where('status', 'published')
            ->with(['ticketTypes' => function ($query) {
                $query->withsum([
                    'holds as confirmed_holds' => function ($q) {
                        $q->where('status', 'confirmed');
                    },
                ], 'quantity');

                $query->withsum([
                    'holds as active_holds' => function ($q) {
                        $q->where('status', 'held')
                            ->where('expires_at', '>', now());
                    },
                ], 'quantity');
            },

            ]);

        // Search by event name
        if ($request->filled('name')) {
            $query->where(
                'title', 'like', '%'.$request->name.'%'
            );
        }

        // Filter by town
        if ($request->filled('town')) {
            $query->where(
                'town', 'like', '%'.$request->town.'%'
            );
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate(
                'date',
                '>=',
                $request->date_from
            );
        }

        if ($request->filled('date_to')) {
            $query->whereDate(
                'date',
                '<=',
                $request->date_to
            );
        }

        // Filter by ticket price
        if ($request->filled('min_price')) {
            $query->whereHas('ticketTypes', function ($q) use ($request) {
                $q->whereRaw('ROUND(base_price * (100 - discount) / 100, 0) >= ?', [$request->min_price]);
            });
        }

        if ($request->filled('max_price')) {
            $query->whereHas('ticketTypes', function ($q) use ($request) {
                $q->whereRaw('ROUND(base_price * (100 - discount) / 100, 0) <= ?', [$request->max_price]);
            });
        }

        $events = $query
            ->latest('date')
            ->paginate(20);

        $events->getCollection()->transform(function ($event) {
            $event->ticketTypes->each(function ($ticketType) {

                $ticketType->remaining =
                    $ticketType->quantity
                    - ($ticketType->confirmed_holds ?? 0)
                    - ($ticketType->active_holds ?? 0);
            });

            return $event;
        });

        return response()->json([
            'events' => $events,
        ]);
    }
}
