<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use LogicException;

class TicketCredentialService
{
    /** @return array{credential: string, key_id: string, code_hash: string} */
    public function generate(string $ticketPublicId, int $eventId, int $userId, ?string $keyId = null): array
    {
        $keyId ??= (string) config('ticketing.active_ticket_key_id');
        $key = $this->key($keyId);
        $payload = [
            'version' => 1,
            'ticket_id' => $ticketPublicId,
            'event_id' => $eventId,
            'user_id' => $userId,
            'key_id' => $keyId,
        ];
        $encodedPayload = $this->encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $credential = $encodedPayload.'.'.$this->encode(hash_hmac('sha256', $encodedPayload, $key, true));

        return [
            'credential' => $credential,
            'key_id' => $keyId,
            'code_hash' => hash('sha256', $credential),
        ];
    }

    /** @return array{version: int, ticket_id: string, event_id: int, user_id: int, key_id: string} */
    public function verify(string $credential): array
    {
        $parts = explode('.', $credential);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        $payloadJson = $this->decode($parts[0]);
        $signature = $this->decode($parts[1]);
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['ticket_id'] ?? null)
            || ! is_numeric($payload['event_id'] ?? null)
            || ! is_numeric($payload['user_id'] ?? null)
            || ! is_string($payload['key_id'] ?? null)) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        $expectedSignature = hash_hmac('sha256', $parts[0], $this->key($payload['key_id']), true);
        if (! hash_equals($expectedSignature, $signature)) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        return $payload;
    }

    private function key(string $keyId): string
    {
        $encodedKey = config("ticketing.ticket_signing_keys.{$keyId}");
        $key = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;

        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException('The ticket signing key must be a base64-encoded secret of at least 32 bytes.');
        }

        return $key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new InvalidArgumentException('Invalid ticket credential.');
        }

        return $decoded;
    }
}
