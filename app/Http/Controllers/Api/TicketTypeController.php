<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\TicketTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketTypeController extends Controller
{
    public function index(Event $event, TicketTypeService $service): JsonResponse
    {
        return response()->json([
            'ticket_types' => $service->listForEvent($event, request()->user()),
        ]);
    }

    public function store(Request $request, Event $event, TicketTypeService $service): JsonResponse
    {
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('ticket_types')->where('event_id', $event->id)],
            'base_price_cents' => ['required', 'integer', 'min:1'],
            'discount' => ['sometimes', 'integer', 'between:0,100'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);
        $attributes['discount'] ??= 0;

        return response()->json([
            'ticket_type' => $service->create($event, $request->user(), $attributes),
        ], 201);
    }

    public function update(Request $request, TicketType $ticketType, TicketTypeService $service): JsonResponse
    {
        $attributes = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'base_price_cents' => ['sometimes', 'integer', 'min:1'],
            'discount' => ['sometimes', 'integer', 'between:0,100'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json(['ticket_type' => $service->update($ticketType, $request->user(), $attributes)]);
    }

    public function destroy(TicketType $ticketType, TicketTypeService $service): JsonResponse
    {
        $service->delete($ticketType, request()->user());

        return response()->json(['message' => 'Ticket type deleted.']);
    }

    public function inventory(Event $event, TicketTypeService $service): JsonResponse
    {
        return response()->json([
            'event' => $event->title,
            'inventory' => $service->inventory($event, request()->user()),
        ]);
    }
}
