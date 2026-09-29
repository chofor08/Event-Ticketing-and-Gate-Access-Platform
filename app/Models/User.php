<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Orders::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntries::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function gateStaffAssignments(): HasMany
    {
        return $this->hasMany(GateStaffAssignment::class);
    }

    public function gateStaffInvitationsCreated(): HasMany
    {
        return $this->hasMany(GateStaffInvitation::class, 'invited_by_user_id');
    }

    public function gateDevices(): HasMany
    {
        return $this->hasMany(GateDevice::class);
    }

    public function scanAttempts(): HasMany
    {
        return $this->hasMany(ScanAttempt::class, 'staff_id');
    }

    public function isAttendee(): bool
    {
        return $this->role === 'attendee';
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }
}
