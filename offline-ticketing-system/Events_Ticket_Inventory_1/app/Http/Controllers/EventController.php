<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventService;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index()
    {
        $events = Event::where('status', 'published')->paginate(15);

        return response()->json([
            'events' => $events,
        ]);
    }

    public function publish(Request $request, EventService $eventService)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:draft,published,cancelled'],
            'venue' => ['required', 'string', 'max:255'],
            'town' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
        ]);

        $event = $eventService->createEvent($request->user(), $validated);

        return response()->json([
            'message' => 'Event successfully created',
            'event' => $event,
        ], 201);
    }

    public function show(Request $request, Event $event, EventService $eventService)
    {
        return response()->json([
            'event' => $eventService->showEvent($event, $request->user()->id),
        ]);
    }

    public function update(Request $request, Event $event, EventService $eventService)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'venue' => ['sometimes', 'string', 'max:255'],
            'town' => ['sometimes', 'string', 'max:255'],
            'date' => ['sometimes', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i'],
        ]);

        $event = $eventService->updateEvent($event, $request->user()->id, $validated);

        return response()->json([
            'event' => $event,
        ]);
    }

    public function cancel(Request $request, Event $event, EventService $eventService)
    {
        $eventService->cancelEvent($event, $request->user()->id);

        return response()->json([
            'message' => 'Event cancelled successfully',
        ]);
    }
}
