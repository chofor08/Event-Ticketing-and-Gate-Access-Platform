<?php

namespace App\Http\Controllers;

use App\Services\TicketScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function store(Request $request, TicketScanService $scanService): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string'],
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'gate' => ['required', 'string', 'max:200'],
        ]);

        $result = $scanService->scan(
            $data['credential'],
            $data['event_id'],
            $request->user()->id,
            $data['gate']
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
