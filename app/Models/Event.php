<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'organizer_id',
        'title',
        'status',
        'venue',
        'town',
        'description',
        'date',
        'start_time',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function gates(): HasMany
    {
        return $this->hasMany(Gate::class);
    }

    public function gateStaffAssignments(): HasMany
    {
        return $this->hasMany(GateStaffAssignment::class);
    }

    public function gateStaffInvitations(): HasMany
    {
        return $this->hasMany(GateStaffInvitation::class);
    }

    public function startsAt(): Carbon
    {
        $date = $this->getRawOriginal('date');
        $date = Carbon::parse($date ?? (string) $this->getAttribute('date'))->toDateString();

        return Carbon::parse($date.' '.$this->getAttribute('start_time'));
    }
}
