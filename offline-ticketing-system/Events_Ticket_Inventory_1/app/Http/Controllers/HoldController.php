<?php

namespace App\Http\Controllers;

use App\Services\HoldService;
use Illuminate\Http\Request;
use RuntimeException;

class HoldController extends Controller
{
    public function store(Request $request, HoldService $holdService, $ticketType)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $holdService->createHold(
                (int) $ticketType,
                $validated['quantity']
            );

            return response()->json([
                'message' => 'Tickets held successfully.',
                'hold' => $result['hold'],
                'token' => $result['token'],
            ], 201);

        } catch (RuntimeException $e) {

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function release(Request $request, HoldService $holdService, $holdid)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {

            $holdService->releaseHold(
                $validated['token'],
                (int) $holdid
            );

            return response()->json([
                'message' => 'Hold released successfully.',
            ], 200);

        } catch (RuntimeException $e) {

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function confirm(Request $request, HoldService $holdService, $holdid)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {

            $holdService->confirmHold(
                $validated['token'],
                (int) $holdid
            );

            return response()->json([
                'message' => 'Hold confirmed successfully.',
            ], 200);

        } catch (RuntimeException $e) {

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
