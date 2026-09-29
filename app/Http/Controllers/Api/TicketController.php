<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request, TicketCredentialService $credentialService): JsonResponse
    {
        $tickets = $request->user()->tickets()
            ->with(['event', 'orderItem.ticketType'])
            ->latest()
            ->paginate(20);

        $tickets->setCollection($tickets->getCollection()->map(function (Ticket $ticket) use ($credentialService): array {
            $credential = $credentialService->generate(
                $ticket->public_id,
                (int) $ticket->event_id,
                (int) $ticket->user_id,
                $ticket->credential_key_id,
            );

            return [
                'id' => $ticket->getKey(),
                'event_id' => $ticket->event_id,
                'event' => $ticket->event->title,
                'ticket_type' => $ticket->orderItem->ticketType->name,
                'unit_number' => $ticket->unit_number,
                'status' => $ticket->status,
                'credential' => $credential['credential'],
            ];
        }));

        return response()->json(['tickets' => $tickets]);
    }
}
