<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\TicketTypeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketTypeController extends Controller
{
    public function index(Request $request, Event $event, TicketTypeService $ticketTypeService)
    {
        return response()->json([
            'ticket_types' => $ticketTypeService->listForEvent($event, $request->user()->id),
        ]);
    }

    public function store(Request $request, Event $event, TicketTypeService $ticketTypeService)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('ticket_types')
                ->where(fn ($query) => $query->where('event_id', $event->id))],
            'price' => ['required', 'integer', 'min:1'],
            'discount' => ['nullable', 'integer', 'min:0', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $ticketType = $ticketTypeService->createTicketType($event, $request->user()->id, $validated);

        return response()->json([
            'message' => 'Ticket type successfully created',
            'ticket_type' => $ticketType,
        ], 201);
    }

    public function update(Request $request, TicketType $ticketType, TicketTypeService $ticketTypeService)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'integer', 'min:1'],
            'discount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ]);

        $ticketType = $ticketTypeService->updateTicketType($ticketType, $request->user()->id, $validated);

        return response()->json([
            'message' => 'Ticket type successfully updated',
            'ticket_type' => $ticketType,
        ]);
    }

    public function destroy(Request $request, TicketType $ticketType, TicketTypeService $ticketTypeService)
    {
        $ticketTypeService->deleteTicketType($ticketType, $request->user()->id);

        return response()->json([
            'message' => 'Ticket type successfully deleted',
        ]);
    }

    public function inventory(Request $request, Event $event, TicketTypeService $ticketTypeService)
    {
        $ticketTypes = $ticketTypeService->inventoryForEvent($event, $request->user()->id);

        return response()->json([
            'event' => $event->title,
            'inventory' => $ticketTypes,
        ]);
    }
}
