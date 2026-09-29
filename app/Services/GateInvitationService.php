<?php

namespace App\Services;

use App\Mail\GateStaffInvitationMail;
use App\Models\Event;
use App\Models\GateStaffAssignment;
use App\Models\GateStaffInvitation;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use LogicException;

class GateInvitationService
{
    public function __construct(private GateAccessService $accessService) {}

    public function invite(Event $event, User $organizer, string $email): GateStaffInvitation
    {
        $this->accessService->ensureOrganizerOwnsEvent($event, $organizer);

        $invitationUrl = config('ticketing.invitation_url');
        if (! is_string($invitationUrl) || $invitationUrl === '') {
            throw new LogicException('The gate client invitation URL is not configured.');
        }

        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($event, $organizer, $email, $token): GateStaffInvitation {
            GateStaffInvitation::query()
                ->where('event_id', $event->getKey())
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return GateStaffInvitation::create([
                'event_id' => $event->getKey(),
                'invited_by_user_id' => $organizer->getKey(),
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(24),
            ]);
        });

        $separator = str_contains($invitationUrl, '?') ? '&' : '?';
        $acceptanceUrl = $invitationUrl.$separator.http_build_query(['token' => $token]);
        Mail::to($email)->queue(new GateStaffInvitationMail(
            $event->title,
            $acceptanceUrl,
            $invitation->expires_at,
        ));

        return $invitation;
    }

    /** @param array{name?: string, password?: string} $accountData */
    public function accept(string $token, ?User $authenticatedUser, array $accountData = []): User
    {
        return DB::transaction(function () use ($token, $authenticatedUser, $accountData): User {
            $invitation = GateStaffInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->accepted_at || $invitation->revoked_at
                || $invitation->expires_at->isPast()) {
                throw (new ModelNotFoundException)->setModel(GateStaffInvitation::class);
            }

            $existingUser = User::query()->where('email', $invitation->email)->lockForUpdate()->first();
            if ($existingUser) {
                if (! $authenticatedUser) {
                    throw new AuthenticationException('Sign in with the invited email address to accept this invitation.');
                }

                if ((int) $authenticatedUser->getKey() !== (int) $existingUser->getKey()) {
                    throw new AuthenticationException('Sign in with the invited email address to accept this invitation.');
                }

                $user = $existingUser;
            } else {
                if ($authenticatedUser || ! isset($accountData['name'], $accountData['password'])) {
                    throw ValidationException::withMessages([
                        'account' => 'Name and password are required to create a gate staff account.',
                    ]);
                }

                $user = User::create([
                    'name' => $accountData['name'],
                    'email' => $invitation->email,
                    'password' => Hash::make($accountData['password']),
                    'role' => 'gate_staff',
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $event = $invitation->event;
            if ($this->accessService->attendeeHasEventTicket($user, $event)) {
                throw ValidationException::withMessages([
                    'invitation' => 'An attendee with a ticket for this event cannot be assigned as gate staff.',
                ]);
            }

            GateStaffAssignment::query()->firstOrCreate(
                ['event_id' => $event->getKey(), 'user_id' => $user->getKey()],
                [
                    'invitation_id' => $invitation->getKey(),
                    'assigned_by_user_id' => $invitation->invited_by_user_id,
                ],
            );
            $invitation->update([
                'accepted_by_user_id' => $user->getKey(),
                'accepted_at' => now(),
            ]);

            return $user;
        });
    }

    public function revoke(GateStaffInvitation $invitation, User $organizer): void
    {
        $this->accessService->ensureOrganizerOwnsEvent($invitation->event, $organizer);

        if (! $invitation->accepted_at) {
            $invitation->update(['revoked_at' => now()]);
        }
    }

    public function revokeAssignment(Event $event, User $staff, User $organizer): void
    {
        $this->accessService->ensureOrganizerOwnsEvent($event, $organizer);
        GateStaffAssignment::query()
            ->where('event_id', $event->getKey())
            ->where('user_id', $staff->getKey())
            ->delete();
        $staff->gateDevices()->whereHas('gate', fn ($query) => $query->where('event_id', $event->getKey()))
            ->update(['revoked_at' => now()]);
    }
}
