<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Gate;
use App\Models\GateDevice;
use App\Services\GateAccessService;
use App\Services\GateDeviceSnapshotService;
use App\Services\TicketScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GateScanController extends Controller
{
    private const array OUTCOMES = [
        'admitted',
        'already_used',
        'wrong_event',
        'cancelled_or_refunded',
        'invalid_code',
    ];

    public function store(
        Event $event,
        Gate $gate,
        Request $request,
        TicketScanService $scanService,
    ): JsonResponse {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:4096'],
            'attempt_id' => ['required', 'uuid'],
        ]);
        $result = $scanService->scanOnline(
            $data['credential'],
            $event,
            $gate,
            $request->user(),
            $data['attempt_id'],
        );

        return response()->json(['data' => $result]);
    }

    public function sync(
        GateDevice $device,
        Request $request,
        GateAccessService $accessService,
        GateDeviceSnapshotService $snapshotService,
        TicketScanService $scanService,
    ): JsonResponse {
        $accessService->ensureDeviceAccess($device, $request->user());
        $data = $request->validate([
            'snapshot_id' => ['required', 'uuid'],
            'attempts' => ['required', 'array', 'min:1', 'max:500'],
        ]);
        $snapshot = $snapshotService->findForSync($device, $data['snapshot_id']);
        $results = [];

        foreach ($data['attempts'] as $index => $attempt) {
            if (! is_array($attempt)) {
                $results[] = [
                    'index' => $index,
                    'accepted' => false,
                    'errors' => ['attempt' => ['Each scan attempt must be an object.']],
                ];

                continue;
            }

            $validator = Validator::make($attempt, [
                'attempt_id' => ['required', 'uuid'],
                'credential' => ['required', 'string', 'max:4096'],
                'scanned_at' => ['required', 'date'],
                'reported_outcome' => ['required', Rule::in(self::OUTCOMES)],
            ]);
            if ($validator->fails()) {
                $results[] = [
                    'index' => $index,
                    'accepted' => false,
                    'errors' => $validator->errors(),
                ];

                continue;
            }

            $results[] = [
                'index' => $index,
                'accepted' => true,
                'data' => $scanService->syncAttempt($device, $request->user(), $snapshot, $validator->validated()),
            ];
        }

        return response()->json(['results' => $results]);
    }
}
