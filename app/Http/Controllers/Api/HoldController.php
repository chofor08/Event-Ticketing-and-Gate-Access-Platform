<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\TicketType;
use App\Services\HoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    public function store(Request $request, TicketType $ticketType, HoldService $service): JsonResponse
    {
        $validated = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);
        $result = $service->create($ticketType, $request->user(), $validated['quantity']);

        return response()->json([
            'hold' => $result['hold'],
            'token' => $result['token'],
        ], 201);
    }

    public function release(Request $request, Hold $hold, HoldService $service): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string']]);
        $service->release($hold, $request->user(), $validated['token']);

        return response()->json(['message' => 'Hold released.']);
    }
}
