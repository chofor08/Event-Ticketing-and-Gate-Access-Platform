<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.'], 400);
        }

        $userId = $request->user()->getKey();

        try {
            // Atomically claim the key before checkout work begins.
            $record = DB::transaction(fn (): IdempotencyKey => IdempotencyKey::create([
                'user_id' => $userId,
                'key' => $key,
                'status' => 'processing',
            ]));
        } catch (QueryException) {
            // A unique-key conflict means this attendee has already submitted the request.
            $record = IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('key', $key)
                ->first();

            if ($record?->status === 'completed') {
                // Return the saved response without creating another order or Stripe session.
                return response()->json($record->response_body, $record->response_status);
            }

            if ($record?->status === 'processing') {
                // A concurrent duplicate is still in flight.
                return response()->json(['message' => 'This request is already being processed.'], 409);
            }

            if (! $record) {
                return response()->json(['message' => 'This request could not be claimed.'], 409);
            }

            $record->update([
                'status' => 'processing',
                'response_status' => null,
                'response_body' => null,
            ]);
        }

        try {
            // Run the checkout action only after the key has been claimed.
            $response = $next($request);
        } catch (\Throwable $exception) {
            $record->update([
                'status' => 'failed',
                'response_status' => 500,
                'response_body' => ['message' => 'The request failed.'],
            ]);

            throw $exception;
        }

        // Save the response so later requests with the same key can replay it.
        $body = json_decode($response->getContent(), true);
        $record->update([
            'status' => $response->isSuccessful() ? 'completed' : 'failed',
            'response_status' => $response->getStatusCode(),
            'response_body' => is_array($body) ? $body : ['message' => 'Request completed.'],
        ]);

        return $response;
    }
}
