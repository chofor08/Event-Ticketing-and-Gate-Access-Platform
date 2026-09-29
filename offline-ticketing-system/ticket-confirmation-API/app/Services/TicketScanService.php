<?php

namespace App\Services;

use App\Models\Scan;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

class TicketScanService
{
    public function scan(string $credential, int $eventId, int $staffId, string $gate): array
    {
        try {
            $payload = app(TicketCredentialService::class)->verify($credential);
        } catch (InvalidArgumentException|JsonException) {
            return $this->logAndReturn(null, $eventId, $staffId, $gate, 'invalid_code');
        }

        return DB::transaction(function () use ($payload, $eventId, $staffId, $gate): array {
            $ticket = Ticket::query()
                ->where('public_id', $payload['ticket_id'])
                ->lockForUpdate()
                ->first();

            if (! $ticket
                || (int) $ticket->event_id !== $payload['event_id']
                || (int) $ticket->attendee_id !== $payload['attendee_id']) {
                return $this->logAndReturn(null, $eventId, $staffId, $gate, 'invalid_code');
            }

            if ((int) $ticket->event_id !== $eventId) {
                return $this->logAndReturn($ticket, $eventId, $staffId, $gate, 'wrong_event');
            }

            $isRefunded = $ticket->status === 'refunded'
                || $ticket->order()->where('status', 'refunded')->exists()
                || DB::table('refund_requests')
                    ->where('order_id', $ticket->order_id)
                    ->where('status', 'succeeded')
                    ->whereJsonContains('order_item_ids', $ticket->order_item_id)
                    ->exists();
            $isEventCancelled = DB::table('events')
                ->where('id', $ticket->event_id)
                ->where('status', 'cancelled')
                ->exists();

            if ($isRefunded || $isEventCancelled || $ticket->status === 'cancelled') {
                return $this->logAndReturn($ticket, $eventId, $staffId, $gate, 'cancelled/refunded');
            }

            if ($ticket->status === 'admitted') {
                return $this->logAndReturn($ticket, $eventId, $staffId, $gate, 'already_used');
            }

            if ($ticket->status !== 'issued') {
                return $this->logAndReturn($ticket, $eventId, $staffId, $gate, 'invalid_code');
            }

            $ticket->update([
                'status' => 'admitted',
                'admitted_at' => now(),
                'admitted_gate' => $gate,
            ]);

            return $this->logAndReturn($ticket->refresh(), $eventId, $staffId, $gate, 'admitted');
        });
    }

    private function logAndReturn(
        ?Ticket $ticket,
        int $eventId,
        int $staffId,
        string $gate,
        string $outcome,
    ): array {
        Scan::create([
            'ticket_id' => $ticket?->getKey(),
            'event_id' => $eventId,
            'staff_id' => $staffId,
            'gate' => $gate,
            'outcome' => $outcome,
            'scanned_at' => now(),
        ]);

        $result = ['outcome' => $outcome];
        if (in_array($outcome, ['admitted', 'already_used'], true) && $ticket !== null) {
            $result += [
                'ticket_id' => $ticket->getKey(),
                'admitted_at' => $ticket->admitted_at,
                'gate' => $ticket->admitted_gate,
            ];
        }

        return $result;
    }
}
