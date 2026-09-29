<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\GateStaffInvitation;
use App\Models\User;
use App\Services\GateAccessService;
use App\Services\GateInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GateStaffInvitationController extends Controller
{
    public function index(Event $event, Request $request, GateAccessService $accessService): JsonResponse
    {
        $accessService->ensureOrganizerOwnsEvent($event, $request->user());

        return response()->json([
            'invitations' => $event->gateStaffInvitations()->with('acceptedBy:id,name,email,role')->latest()->get(),
            'assignments' => $event->gateStaffAssignments()->with('user:id,name,email,role')->latest()->get(),
        ]);
    }

    public function store(
        Event $event,
        Request $request,
        GateInvitationService $invitationService,
    ): JsonResponse {
        $data = $request->validate(['email' => ['required', 'email', 'lowercase', 'max:255']]);
        $invitation = $invitationService->invite($event, $request->user(), $data['email']);

        return response()->json([
            'invitation' => [
                'id' => $invitation->getKey(),
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at,
            ],
        ], 201);
    }

    public function destroy(
        Event $event,
        GateStaffInvitation $invitation,
        Request $request,
        GateInvitationService $invitationService,
    ): JsonResponse {
        abort_unless((int) $invitation->event_id === (int) $event->getKey(), 404);
        $invitationService->revoke($invitation, $request->user());

        return response()->json(['revoked' => true]);
    }

    public function revokeAssignment(
        Event $event,
        User $staff,
        Request $request,
        GateInvitationService $invitationService,
    ): JsonResponse {
        abort_unless($event->gateStaffAssignments()->where('user_id', $staff->getKey())->exists(), 404);
        $invitationService->revokeAssignment($event, $staff, $request->user());

        return response()->json(['revoked' => true]);
    }
}
