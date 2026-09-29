<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Gate;
use App\Models\GateDevice;
use App\Models\GateStaffAssignment;
use App\Models\OrderItems;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GateAccessService
{
    public function ensureOrganizerOwnsEvent(Event $event, User $user): void
    {
        if ((int) $event->organizer_id !== (int) $user->getKey()) {
            throw new AuthorizationException('You do not own this event.');
        }
    }

    public function ensureAssignedToEvent(Event $event, User $user): void
    {
        $isAssigned = GateStaffAssignment::query()
            ->where('event_id', $event->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $isAssigned) {
            throw new AuthorizationException('You are not assigned to this event.');
        }
    }

    public function ensureGateBelongsToEvent(Gate $gate, Event $event): void
    {
        if ((int) $gate->event_id !== (int) $event->getKey()) {
            throw (new ModelNotFoundException)->setModel(Gate::class, [$gate->getKey()]);
        }
    }

    public function ensureGateCanScan(Gate $gate, Event $event): void
    {
        $this->ensureGateBelongsToEvent($gate, $event);
        if ($gate->status !== 'active') {
            throw (new ModelNotFoundException)->setModel(Gate::class, [$gate->getKey()]);
        }
    }

    public function ensureDeviceAccess(GateDevice $device, User $user): void
    {
        if ((int) $device->user_id !== (int) $user->getKey() || $device->revoked_at !== null) {
            throw new AuthorizationException('This device is not available to the authenticated user.');
        }

        $this->ensureAssignedToEvent($device->gate->event, $user);
        $this->ensureGateCanScan($device->gate, $device->gate->event);
    }

    public function attendeeHasEventTicket(User $user, Event $event): bool
    {
        if ($user->role !== 'attendee') {
            return false;
        }

        return Ticket::query()
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->exists()
            || OrderItems::query()
                ->whereHas('order', fn ($query) => $query->where('user_id', $user->getKey()))
                ->whereHas('ticketType', fn ($query) => $query->where('event_id', $event->getKey()))
                ->exists();
    }
}
