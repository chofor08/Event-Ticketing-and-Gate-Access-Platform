<?php

namespace App\Services;

use App\Models\GateDevice;
use App\Models\GateDeviceSnapshot;
use App\Models\GateDeviceSnapshotTicket;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class GateDeviceSnapshotService
{
    private const int PAGE_SIZE = 500;

    public function __construct(private GateAccessService $accessService) {}

    /** @return array<string, mixed> */
    public function page(GateDevice $device, User $user, ?string $snapshotId, ?int $cursor): array
    {
        $device->loadMissing('gate.event');
        $this->accessService->ensureDeviceAccess($device, $user);
        if ($this->accessService->attendeeHasEventTicket($user, $device->gate->event)) {
            throw new AuthorizationException(
                'An attendee with an event ticket cannot download this event ticket roster.',
            );
        }

        $snapshot = $snapshotId
            ? $device->snapshots()->where('public_id', $snapshotId)->first()
            : $device->snapshots()->where('expires_at', '>', now())->latest('issued_at')->first();

        if ($snapshotId && ! $snapshot) {
            throw ValidationException::withMessages(['snapshot_id' => 'The snapshot does not belong to this device.']);
        }

        if (! $snapshot || (! $snapshotId && $snapshot->expires_at->isPast())) {
            $snapshot = $this->create($device);
        }

        if ($snapshot->expires_at->isPast()) {
            throw ValidationException::withMessages(['snapshot_id' => 'The snapshot has expired.']);
        }

        $tickets = GateDeviceSnapshotTicket::query()
            ->where('snapshot_id', $snapshot->getKey())
            ->when($cursor, fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get(['id', 'ticket_id', 'code_hash', 'ticket_status']);

        $hasMore = $tickets->count() > self::PAGE_SIZE;
        if ($hasMore) {
            $tickets->pop();
        }

        return [
            'snapshot' => [
                'id' => $snapshot->public_id,
                'event_id' => $snapshot->event_id,
                'event_status' => $snapshot->event_status,
                'device_id' => $device->public_id,
                'issued_at' => $snapshot->issued_at->toIso8601String(),
                'expires_at' => $snapshot->expires_at->toIso8601String(),
                'payload_hash' => $snapshot->payload_hash,
                'signing_key_id' => $snapshot->signing_key_id,
                'signature' => $snapshot->signature,
                'verification_public_key' => config("ticketing.snapshot_public_keys.{$snapshot->signing_key_id}"),
            ],
            'tickets' => $tickets->map(fn (GateDeviceSnapshotTicket $ticket): array => [
                'ticket_id' => $ticket->ticket_id,
                'code_hash' => $ticket->code_hash,
                'status' => $ticket->ticket_status,
            ])->values()->all(),
            'next_cursor' => $hasMore ? $tickets->last()->id : null,
            'complete' => ! $hasMore,
        ];
    }

    public function findForSync(GateDevice $device, string $snapshotId): GateDeviceSnapshot
    {
        $snapshot = $device->snapshots()->where('public_id', $snapshotId)->first();
        if (! $snapshot) {
            throw ValidationException::withMessages(['snapshot_id' => 'The snapshot does not belong to this device.']);
        }

        if (now()->greaterThan($snapshot->expires_at->copy()->addDays(
            (int) config('ticketing.snapshot_retention_days', 30),
        ))) {
            throw ValidationException::withMessages(['snapshot_id' => 'The late sync retention window has expired.']);
        }

        $this->verify($snapshot);

        return $snapshot;
    }

    private function create(GateDevice $device): GateDeviceSnapshot
    {
        $privateKey = config('ticketing.snapshot_private_key');
        $keyId = config('ticketing.snapshot_key_id');
        $publicKey = is_string($keyId) ? config("ticketing.snapshot_public_keys.{$keyId}") : null;
        if (! is_string($privateKey) || $privateKey === '' || ! is_string($publicKey) || $publicKey === ''
            || ! is_string($keyId) || $keyId === '') {
            throw new LogicException('Gate snapshot signing keys are not configured.');
        }

        return DB::transaction(function () use ($device, $privateKey, $publicKey, $keyId): GateDeviceSnapshot {
            $device->loadMissing('gate.event');
            $event = $device->gate->event;
            $issuedAt = now();
            $expiresAt = $issuedAt->copy()->addSeconds((int) config('ticketing.snapshot_lifetime_seconds', 86400));
            $publicId = (string) Str::uuid();
            $tickets = Ticket::query()
                ->where('event_id', $event->getKey())
                ->orderBy('id')
                ->get(['id', 'code_hash', 'status']);
            $entries = $tickets->map(fn (Ticket $ticket): array => [
                'ticket_id' => $ticket->getKey(),
                'code_hash' => $ticket->code_hash,
                'status' => $ticket->status,
            ])->all();
            $payloadHash = hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $manifest = [
                'id' => $publicId,
                'device_id' => $device->public_id,
                'event_id' => $event->getKey(),
                'event_status' => $event->status,
                'payload_hash' => $payloadHash,
                'signing_key_id' => $keyId,
                'issued_at' => $issuedAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ];
            $canonicalManifest = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $key = openssl_pkey_get_private($privateKey);
            if (! $key || ! openssl_sign($canonicalManifest, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new LogicException('The gate snapshot signing key is invalid.');
            }
            if (openssl_verify($canonicalManifest, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                throw new LogicException('The gate snapshot public and private keys do not match.');
            }

            $snapshot = GateDeviceSnapshot::create([
                'public_id' => $publicId,
                'device_id' => $device->getKey(),
                'event_id' => $event->getKey(),
                'event_status' => $event->status,
                'payload_hash' => $payloadHash,
                'signing_key_id' => $keyId,
                'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
            ]);

            $now = now();
            foreach (array_chunk($entries, 500) as $chunk) {
                GateDeviceSnapshotTicket::query()->insert(array_map(
                    fn (array $entry): array => [
                        'snapshot_id' => $snapshot->getKey(),
                        'ticket_id' => $entry['ticket_id'],
                        'code_hash' => $entry['code_hash'],
                        'ticket_status' => $entry['status'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $chunk,
                ));
            }

            $device->update(['last_snapshot_at' => $issuedAt]);

            return $snapshot;
        });
    }

    private function verify(GateDeviceSnapshot $snapshot): void
    {
        $publicKey = config("ticketing.snapshot_public_keys.{$snapshot->signing_key_id}");
        if (! is_string($publicKey) || $publicKey === '') {
            throw new LogicException('Gate snapshot verification key is not configured.');
        }

        $manifest = [
            'id' => $snapshot->public_id,
            'device_id' => $snapshot->device->public_id,
            'event_id' => $snapshot->event_id,
            'event_status' => $snapshot->event_status,
            'payload_hash' => $snapshot->payload_hash,
            'signing_key_id' => $snapshot->signing_key_id,
            'issued_at' => $snapshot->issued_at->toIso8601String(),
            'expires_at' => $snapshot->expires_at->toIso8601String(),
        ];
        $signature = base64_decode(strtr($snapshot->signature, '-_', '+/'), true);
        if (! is_string($signature)
            || openssl_verify(
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $signature,
                config("ticketing.snapshot_public_keys.{$snapshot->signing_key_id}"),
                OPENSSL_ALGO_SHA256,
            ) !== 1) {
            throw new LogicException('The gate snapshot signature is invalid.');
        }

        $entries = $snapshot->tickets()
            ->orderBy('ticket_id')
            ->get(['ticket_id', 'code_hash', 'ticket_status'])
            ->map(fn (GateDeviceSnapshotTicket $ticket): array => [
                'ticket_id' => $ticket->ticket_id,
                'code_hash' => $ticket->code_hash,
                'status' => $ticket->ticket_status,
            ])->all();
        $payloadHash = hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (! hash_equals($snapshot->payload_hash, $payloadHash)) {
            throw new LogicException('The gate snapshot roster does not match its signed manifest.');
        }
    }
}
