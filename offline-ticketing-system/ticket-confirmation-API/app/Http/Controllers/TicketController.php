<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = $request->user()
            ->tickets()
            ->with(['event', 'ticketType'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $tickets,
        ]);
    }
}
