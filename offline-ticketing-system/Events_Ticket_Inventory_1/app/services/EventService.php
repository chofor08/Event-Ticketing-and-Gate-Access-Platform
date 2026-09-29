<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventService
{
    public function createEvent(User $organizer, array $validated): Event
    {
        return $organizer->events()->create($validated);
    }

    public function showEvent(Event $event, int $organizerId): Event
    {
        $this->ensureOrganizerOwnsEvent($event, $organizerId);

        return $event;
    }

    public function updateEvent(Event $event, int $organizerId, array $validated): Event
    {
        $this->ensureOrganizerOwnsEvent($event, $organizerId);
        $this->ensureEventIsNotCancelled($event);

        $event->update($validated);

        return $event->refresh();
    }

    public function cancelEvent(Event $event, int $organizerId): void
    {
        DB::transaction(function () use ($event, $organizerId): void {
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOrganizerOwnsEvent($event, $organizerId);

            if ($event->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'event' => 'Event is already cancelled.',
                ]);
            }

            $event->ticketTypes()->each(function ($ticketType): void {
                $ticketType->holds()
                    ->where('status', 'held')
                    ->where('expires_at', '>', now())
                    ->update(['status' => 'released']);
            });

            $event->update(['status' => 'cancelled']);
        });
    }

    private function ensureOrganizerOwnsEvent(Event $event, int $organizerId): void
    {
        if ($event->organizer_id !== $organizerId) {
            throw new AuthorizationException('Unauthorized');
        }
    }

    private function ensureEventIsNotCancelled(Event $event): void
    {
        if ($event->status === 'cancelled') {
            throw ValidationException::withMessages([
                'event' => "You can't edit a cancelled event.",
            ]);
        }
    }
}
