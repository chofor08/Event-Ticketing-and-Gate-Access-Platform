<?php

namespace App\Services;

use InvalidArgumentException;
use LogicException;

class TicketCredentialService
{
    public function generate(string $ticketId, int $eventId, int $attendeeId): string
    {
        $payload = implode(':', [
            $ticketId,
            $eventId,
            $attendeeId,
            bin2hex(random_bytes(32)),
        ]);

        return $payload.':'.hash_hmac('sha256', $payload, $this->signingKey());
    }

    public function verify(string $credential): array
    {
        $parts = explode(':', $credential);
        if (count($parts) !== 5) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        $signature = array_pop($parts);
        $payload = implode(':', $parts);
        if (! hash_equals(hash_hmac('sha256', $payload, $this->signingKey()), $signature)) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        [$ticketId, $eventId, $attendeeId, $nonce] = $parts;
        if ($ticketId === ''
            || ! ctype_digit($eventId)
            || ! ctype_digit($attendeeId)
            || preg_match('/^[a-f0-9]{64}$/D', $nonce) !== 1) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        return [
            'ticket_id' => $ticketId,
            'event_id' => (int) $eventId,
            'attendee_id' => (int) $attendeeId,
        ];
    }

    private function signingKey(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new LogicException('The application key must be configured to sign ticket credentials.');
        }

        return $key;
    }
}
