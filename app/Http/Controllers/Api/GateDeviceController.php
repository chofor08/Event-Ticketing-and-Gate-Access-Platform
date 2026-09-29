<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Gate;
use App\Models\GateDevice;
use App\Models\User;
use App\Services\GateAccessService;
use App\Services\GateDeviceSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GateDeviceController extends Controller
{
    public function index(
        Event $event,
        Gate $gate,
        Request $request,
        GateAccessService $accessService,
    ): JsonResponse {
        $accessService->ensureOrganizerOwnsEvent($event, $request->user());
        $accessService->ensureGateBelongsToEvent($gate, $event);

        return response()->json(['devices' => $gate->devices()->with('user:id,name,email,role')->latest()->get()]);
    }

    public function store(
        Event $event,
        Gate $gate,
        Request $request,
        GateAccessService $accessService,
    ): JsonResponse {
        $accessService->ensureOrganizerOwnsEvent($event, $request->user());
        $accessService->ensureGateBelongsToEvent($gate, $event);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $staff = User::query()->findOrFail($data['user_id']);
        $accessService->ensureAssignedToEvent($event, $staff);
        if ($accessService->attendeeHasEventTicket($staff, $event)) {
            throw ValidationException::withMessages([
                'user_id' => 'An attendee with a ticket for this event cannot be assigned as gate staff.',
            ]);
        }

        $device = GateDevice::create([
            'public_id' => (string) Str::uuid(),
            'gate_id' => $gate->getKey(),
            'user_id' => $staff->getKey(),
            'name' => $data['name'],
        ]);

        return response()->json(['device' => $device], 201);
    }

    public function destroy(
        Event $event,
        Gate $gate,
        GateDevice $device,
        Request $request,
        GateAccessService $accessService,
    ): JsonResponse {
        $accessService->ensureOrganizerOwnsEvent($event, $request->user());
        $accessService->ensureGateBelongsToEvent($gate, $event);
        abort_unless((int) $device->gate_id === (int) $gate->getKey(), 404);
        $device->update(['revoked_at' => now()]);

        return response()->json(['revoked' => true]);
    }

    public function snapshot(
        GateDevice $device,
        Request $request,
        GateDeviceSnapshotService $snapshotService,
    ): JsonResponse {
        $data = $request->validate([
            'snapshot_id' => ['sometimes', 'uuid'],
            'cursor' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($snapshotService->page(
            $device,
            $request->user(),
            $data['snapshot_id'] ?? null,
            isset($data['cursor']) ? (int) $data['cursor'] : null,
        ));
    }
}
