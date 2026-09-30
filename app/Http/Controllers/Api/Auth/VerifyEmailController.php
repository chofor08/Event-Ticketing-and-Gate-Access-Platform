<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /** Verify an email using Laravel's signed verification URL. */
    public function __invoke(Request $request, int $id, string $hash): JsonResponse
    {
        return response()->json(['message' => $this->verify($id, $hash)]);
    }

    public function web(int $id, string $hash): RedirectResponse
    {
        $this->verify($id, $hash);

        return redirect()->route('home', ['verified' => '1']);
    }

    private function verify(int $id, string $hash): string
    {
        $user = User::query()->findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if ($user->hasVerifiedEmail()) {
            return 'Email address is already verified.';
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return 'Email address verified.';
    }
}
