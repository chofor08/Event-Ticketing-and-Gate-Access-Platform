<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GateDeviceSnapshot extends Model
{
    protected $fillable = [
        'public_id',
        'device_id',
        'event_id',
        'event_status',
        'payload_hash',
        'signing_key_id',
        'signature',
        'issued_at',
        'expires_at',
    ];

    protected $hidden = ['payload_hash', 'signature'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(GateDevice::class, 'device_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scanAttempts(): HasMany
    {
        return $this->hasMany(ScanAttempt::class, 'snapshot_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(GateDeviceSnapshotTicket::class, 'snapshot_id');
    }
}
