<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Gate;
use App\Models\ScanAttempt;
use App\Services\GateAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GateController extends Controller
{
    public function index(Event $event, Request $request, GateAccessService $accessService): JsonResponse
    {
        $accessService->ensureOrganizerOwnsEvent($event, $request->user());

        return response()->json(['gates' => $event->gates()->orderBy('id')->get()]);
    }

    public function store(Event $event, Request $request, GateAccessService $accessService): JsonResponse
    {
        $accessService->ensureOrganizerOwnsEvent($event, $request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('gates')->where('event_id', $event->getKey())],
        ]);

        return response()->json(['gate' => $event->gates()->create($data)], 201);
    }

    public function update(Gate $gate, Request $request, GateAccessService $accessService): JsonResponse
    {
        $accessService->ensureOrganizerOwnsEvent($gate->event, $request->user());
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('gates')->where('event_id', $gate->event_id)->ignore($gate->getKey())],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ]);
        $gate->update($data);

        return response()->json(['gate' => $gate->refresh()]);
    }

    public function destroy(Gate $gate, Request $request, GateAccessService $accessService): JsonResponse
    {
        $accessService->ensureOrganizerOwnsEvent($gate->event, $request->user());
        $gate->update(['status' => 'inactive']);

        return response()->json(['gate' => $gate->refresh()]);
    }

    public function entryCounts(Event $event, Request $request, GateAccessService $accessService): JsonResponse
    {
        $accessService->ensureOrganizerOwnsEvent($event, $request->user());
        $count = ScanAttempt::query()
            ->where('event_id', $event->getKey())
            ->whereNotNull('ticket_id')
            ->whereHas('ticket', fn ($query) => $query->whereIn('status', ['admitted', 'admission_conflict']))
            ->whereRaw("COALESCE(reconciled_outcome, outcome) = 'admitted'")
            ->distinct('ticket_id')
            ->count('ticket_id');

        return response()->json(['event_id' => $event->getKey(), 'admitted_tickets' => $count]);
    }
}
