<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(Request $request, UserService $userService)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $userService->createUser($validated);

        return response()->json([
            'Message' => 'Registration successful',
            'user' => $result['user'],
            'token' => $result['token'],
        ], 201);
    }

    public function login(Request $request, UserService $userService)
    {

        $validated = $request->validate([
            'password' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $result = $userService->loginUser($validated);

        if ($result === null) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        return response()->json([
            'message' => 'Login Successful',
            'user' => $result['user'],
            'token' => $result['token'],
        ]);
    }

    public function logout(Request $request, UserService $userService)
    {
        $userService->logout($request->user());

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }
}
