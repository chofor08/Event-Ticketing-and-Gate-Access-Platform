<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Gate;
use App\Models\GateDevice;
use App\Models\GateDeviceSnapshot;
use App\Models\GateDeviceSnapshotTicket;
use App\Models\ScanAttempt;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use LogicException;

class TicketScanService
{
    private const array OUTCOMES = [
        'admitted',
        'already_used',
        'wrong_event',
        'cancelled_or_refunded',
        'invalid_code',
    ];

    public function __construct(
        private TicketCredentialService $credentialService,
        private GateDeviceSnapshotService $snapshotService,
        private GateAccessService $accessService,
    ) {}

    /** @return array<string, mixed> */
    public function scanOnline(
        string $credential,
        Event $event,
        Gate $gate,
        User $staff,
        string $attemptId,
    ): array {
        $this->accessService->ensureAssignedToEvent($event, $staff);
        $this->accessService->ensureGateCanScan($gate, $event);
        if ($this->accessService->attendeeHasEventTicket($staff, $event)) {
            throw new AuthorizationException('An attendee with an event ticket cannot scan that event.');
        }

        $existing = ScanAttempt::query()->where('attempt_id', $attemptId)->first();
        if ($existing) {
            abort_unless(
                (int) $existing->staff_id === (int) $staff->getKey()
                    && (int) $existing->event_id === (int) $event->getKey()
                    && (int) $existing->gate_id === (int) $gate->getKey(),
                404,
            );

            return $this->result($existing);
        }

        return DB::transaction(function () use ($credential, $event, $gate, $staff, $attemptId): array {
            $existing = ScanAttempt::query()->where('attempt_id', $attemptId)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(
                    (int) $existing->staff_id === (int) $staff->getKey()
                        && (int) $existing->event_id === (int) $event->getKey()
                        && (int) $existing->gate_id === (int) $gate->getKey(),
                    404,
                );

                return $this->result($existing);
            }

            $ticket = $this->resolveTicket($credential);
            if ($ticket) {
                $ticket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            }

            $outcome = $this->decide($credential, $ticket, $event);
            $flags = [];
            if ($outcome === 'admitted' && $ticket) {
                $ticket->update([
                    'status' => 'admitted',
                    'admitted_at' => now(),
                    'admitted_gate_id' => $gate->getKey(),
                ]);
            }

            $attempt = $this->record(
                $attemptId,
                $ticket,
                $credential,
                $event,
                $gate,
                $staff,
                null,
                'online',
                $outcome,
                null,
                null,
                $flags,
                now(),
                now(),
            );

            return $this->result($attempt);
        });
    }

    /** @param array{attempt_id: string, credential: string, scanned_at: string, reported_outcome: string} $input
     * @return array<string, mixed>
     */
    public function syncAttempt(GateDevice $device, User $staff, GateDeviceSnapshot $snapshot, array $input): array
    {
        $this->accessService->ensureDeviceAccess($device, $staff);
        if ((int) $snapshot->device_id !== (int) $device->getKey()) {
            throw new LogicException('The snapshot does not belong to the registered device.');
        }

        $existing = ScanAttempt::query()->where('attempt_id', $input['attempt_id'])->first();
        if ($existing) {
            abort_unless(
                (int) $existing->staff_id === (int) $staff->getKey()
                    && (int) $existing->device_id === (int) $device->getKey()
                    && (int) $existing->snapshot_id === (int) $snapshot->getKey(),
                404,
            );

            return $this->result($existing);
        }

        return DB::transaction(function () use ($device, $staff, $snapshot, $input): array {
            $existing = ScanAttempt::query()->where('attempt_id', $input['attempt_id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(
                    (int) $existing->staff_id === (int) $staff->getKey()
                        && (int) $existing->device_id === (int) $device->getKey()
                        && (int) $existing->snapshot_id === (int) $snapshot->getKey(),
                    404,
                );

                return $this->result($existing);
            }

            $scannedAt = Carbon::parse($input['scanned_at']);
            $event = Event::query()->findOrFail($snapshot->event_id);
            $gate = Gate::query()->findOrFail($device->gate_id);
            $credential = $input['credential'];
            $codeHash = hash('sha256', $credential);
            $snapshotTicket = GateDeviceSnapshotTicket::query()
                ->where('snapshot_id', $snapshot->getKey())
                ->where('code_hash', $codeHash)
                ->first();
            $ticket = $snapshotTicket?->ticket ?? $this->resolveTicket($credential);
            $ticketStatusAtSync = $ticket?->status;
            if ($ticket) {
                $ticket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
                $ticketStatusAtSync = $ticket->status;
            }

            $flags = [];
            if ($scannedAt->lt($snapshot->issued_at) || $scannedAt->gt($snapshot->expires_at)) {
                $flags[] = 'claimed_scan_time_outside_snapshot_window';
            }
            if (now()->gt($snapshot->expires_at)) {
                $flags[] = 'late_sync';
            }
            $flags[] = 'device_time_unverified';
            if ($snapshot->event_status !== $event->status) {
                $flags[] = 'event_state_changed_since_snapshot';
            }
            if ($snapshotTicket && $ticketStatusAtSync !== $snapshotTicket->ticket_status) {
                $flags[] = 'ticket_state_changed_since_snapshot';
            }

            $snapshotTicketStatus = $ticket?->status;
            if ($ticket && $snapshotTicket) {
                $ticket->status = $snapshotTicket->ticket_status;
            }
            $outcome = $this->decide($credential, $ticket, $event, $snapshotTicket, $snapshot);
            if ($ticket && $snapshotTicket) {
                $ticket->status = $snapshotTicketStatus;
            }
            $reportedOutcome = in_array($input['reported_outcome'], self::OUTCOMES, true)
                ? $input['reported_outcome']
                : 'invalid_code';
            if ($reportedOutcome !== $outcome) {
                $flags[] = 'reported_outcome_mismatch';
            }

            if ($outcome === 'admitted' && $ticket) {
                $priorAdmissions = ScanAttempt::query()
                    ->where('ticket_id', $ticket->getKey())
                    ->whereRaw("COALESCE(reconciled_outcome, outcome) = 'admitted'")
                    ->get();

                if ($priorAdmissions->isNotEmpty()) {
                    $flags[] = 'offline_duplicate';
                    $ticket->update([
                        'status' => 'admission_conflict',
                        'admitted_at' => null,
                        'admitted_gate_id' => null,
                        'admission_conflicted_at' => now(),
                    ]);
                    ScanAttempt::query()->whereIn('id', $priorAdmissions->modelKeys())->get()
                        ->each(function (ScanAttempt $priorAttempt): void {
                            $priorAttempt->update([
                                'reconciled_outcome' => 'admitted',
                                'reconciliation_flags' => array_values(array_unique([
                                    ...($priorAttempt->reconciliation_flags ?? []),
                                    'offline_duplicate',
                                ])),
                            ]);
                        });
                } elseif ($ticket->status === 'issued') {
                    $ticket->update([
                        'status' => 'admitted',
                        'admitted_at' => $scannedAt,
                        'admitted_gate_id' => $gate->getKey(),
                    ]);
                }
            }

            if ($ticket && $ticket->status === 'admission_conflict') {
                $flags[] = 'offline_duplicate';
            }

            if ($ticket && $outcome === 'cancelled_or_refunded') {
                $ticket->update([
                    'status' => $event->status === 'cancelled' ? 'cancelled' : 'refunded',
                    'admitted_at' => null,
                    'admitted_gate_id' => null,
                ]);
            }

            $attempt = $this->record(
                $input['attempt_id'],
                $ticket,
                $credential,
                $event,
                $gate,
                $staff,
                $device,
                'offline',
                $reportedOutcome,
                $reportedOutcome,
                $outcome,
                $flags,
                $scannedAt,
                now(),
                $snapshot,
            );

            return $this->result($attempt);
        });
    }

    /** @return array<string, mixed> */
    private function decide(
        string $credential,
        ?Ticket $ticket,
        Event $event,
        ?GateDeviceSnapshotTicket $snapshotTicket = null,
        ?GateDeviceSnapshot $snapshot = null,
    ): string {
        try {
            $payload = $this->credentialService->verify($credential);
        } catch (InvalidArgumentException|JsonException|LogicException) {
            return 'invalid_code';
        }

        if ((int) $payload['event_id'] !== (int) $event->getKey()) {
            return 'wrong_event';
        }

        if ($snapshot && ! $snapshotTicket) {
            return 'invalid_code';
        }

        if (! $ticket
            || $ticket->public_id !== $payload['ticket_id']
            || (int) $ticket->event_id !== $payload['event_id']
            || (int) $ticket->user_id !== $payload['user_id']
            || ! hash_equals($ticket->code_hash, hash('sha256', $credential))) {
            return 'invalid_code';
        }

        if ($snapshotTicket && (int) $snapshotTicket->ticket_id !== (int) $ticket->getKey()) {
            return 'invalid_code';
        }

        if ($snapshot && ((int) $snapshot->event_id !== (int) $event->getKey()
            || $snapshot->event_status === 'cancelled')) {
            return 'cancelled_or_refunded';
        }

        if ($event->status === 'cancelled' || $ticket->status === 'cancelled'
            || $this->ticketHasSucceededRefund($ticket)) {
            return 'cancelled_or_refunded';
        }

        if ($ticket->status === 'refunded') {
            return 'cancelled_or_refunded';
        }

        if (in_array($ticket->status, ['admitted', 'admission_conflict'], true)) {
            return 'already_used';
        }

        return $ticket->status === 'issued' ? 'admitted' : 'invalid_code';
    }

    private function resolveTicket(string $credential): ?Ticket
    {
        try {
            $payload = $this->credentialService->verify($credential);
        } catch (InvalidArgumentException|JsonException|LogicException) {
            return null;
        }

        return Ticket::query()->where('public_id', $payload['ticket_id'])->first();
    }

    private function ticketHasSucceededRefund(Ticket $ticket): bool
    {
        return DB::table('refund_requests')
            ->where('order_id', $ticket->orderItem->order_id)
            ->where('status', 'succeeded')
            ->whereJsonContains('order_item_ids', $ticket->order_item_id)
            ->exists();
    }

    private function record(
        string $attemptId,
        ?Ticket $ticket,
        string $credential,
        Event $event,
        Gate $gate,
        User $staff,
        ?GateDevice $device,
        string $source,
        string $outcome,
        ?string $reportedOutcome,
        ?string $reconciledOutcome,
        array $flags,
        CarbonInterface $scannedAt,
        CarbonInterface $syncedAt,
        ?GateDeviceSnapshot $snapshot = null,
    ): ScanAttempt {
        return ScanAttempt::create([
            'attempt_id' => $attemptId,
            'ticket_id' => $ticket?->getKey(),
            'ticket_code_hash' => hash('sha256', $credential),
            'event_id' => $event->getKey(),
            'staff_id' => $staff->getKey(),
            'gate_id' => $gate->getKey(),
            'device_id' => $device?->getKey(),
            'snapshot_id' => $snapshot?->getKey(),
            'source' => $source,
            'outcome' => $outcome,
            'reported_outcome' => $reportedOutcome,
            'reconciled_outcome' => $reconciledOutcome,
            'reconciliation_flags' => $flags ?: null,
            'scanned_at' => $scannedAt,
            'synced_at' => $syncedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function result(ScanAttempt $attempt): array
    {
        return [
            'attempt_id' => $attempt->attempt_id,
            'outcome' => $attempt->outcome,
            'reconciled_outcome' => $attempt->reconciled_outcome,
            'ticket_id' => $attempt->ticket_id,
            'scanned_at' => $attempt->scanned_at->toIso8601String(),
            'reconciliation_flags' => $attempt->reconciliation_flags ?? [],
        ];
    }
}
