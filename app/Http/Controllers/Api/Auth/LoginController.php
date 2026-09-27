<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    /** Handle an incoming token-authentication request. */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('api-token')->plainTextToken,
        ]);
    }

    /** Revoke all API tokens for the authenticated account. */
    public function destroy(Request $request): Response
    {
        $request->user()->tokens()->delete();

        return response()->noContent();
    }
}
