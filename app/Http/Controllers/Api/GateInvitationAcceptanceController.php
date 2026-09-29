<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GateInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;

class GateInvitationAcceptanceController extends Controller
{
    public function store(Request $request, GateInvitationService $invitationService): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['sometimes', 'required', 'confirmed', PasswordRule::defaults()],
        ]);
        $user = Auth::guard('sanctum')->user();
        $acceptedUser = $invitationService->accept($data['token'], $user, $data);

        $response = ['user' => $acceptedUser, 'accepted' => true];
        if (! $user) {
            $response['token'] = $acceptedUser->createToken('gate-staff-api')->plainTextToken;
        }

        return response()->json($response, 200);
    }
}
