<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EventService;
use App\Services\OrderRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = $request->user()->events()->with('ticketTypes')->latest('date')->paginate(20);

        return response()->json(['events' => $events]);
    }

    public function store(Request $request, EventService $eventService): JsonResponse
    {
        $attributes = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['draft', 'published', 'cancelled'])],
            'venue' => ['required', 'string', 'max:255'],
            'town' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
        ]);

        $event = $eventService->createEvent($request->user(), $attributes);

        return response()->json(['event' => $event], 201);
    }

    public function show(Event $event, EventService $eventService): JsonResponse
    {
        if ($event->status === 'published') {
            return response()->json(['event' => $event->load('ticketTypes')]);
        }

        $eventService->ensureOwner($event, request()->user());

        return response()->json(['event' => $event->load('ticketTypes')]);
    }

    public function showOrganizer(Event $event, EventService $eventService): JsonResponse
    {
        $eventService->ensureOwner($event, request()->user());

        return response()->json(['event' => $event->load('ticketTypes')]);
    }

    public function update(Request $request, Event $event, EventService $eventService): JsonResponse
    {
        $attributes = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'venue' => ['sometimes', 'string', 'max:255'],
            'town' => ['sometimes', 'string', 'max:255'],
            'date' => ['sometimes', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i'],
        ]);

        return response()->json(['event' => $eventService->updateEvent($event, $request->user(), $attributes)]);
    }

    public function cancel(Event $event, EventService $eventService, OrderRefundService $refundService): JsonResponse
    {
        $result = $eventService->cancelEvent($event, request()->user(), $refundService);

        return response()->json($result);
    }
}
