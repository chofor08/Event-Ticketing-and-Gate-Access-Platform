<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'public_id',
        'order_item_id',
        'user_id',
        'event_id',
        'unit_number',
        'credential_key_id',
        'code_hash',
        'status',
        'admitted_at',
        'admitted_gate_id',
        'admission_conflicted_at',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'admission_conflicted_at' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItems::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function admittedGate(): BelongsTo
    {
        return $this->belongsTo(Gate::class, 'admitted_gate_id');
    }

    public function scanAttempts(): HasMany
    {
        return $this->hasMany(ScanAttempt::class);
    }
}
