<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanAttempt extends Model
{
    protected $fillable = [
        'attempt_id',
        'ticket_id',
        'ticket_code_hash',
        'event_id',
        'staff_id',
        'gate_id',
        'device_id',
        'snapshot_id',
        'source',
        'outcome',
        'reconciled_outcome',
        'reconciliation_flags',
        'scanned_at',
        'synced_at',
    ];

    protected $hidden = ['ticket_code_hash'];

    protected function casts(): array
    {
        return [
            'reconciliation_flags' => 'array',
            'scanned_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(GateDevice::class, 'device_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(GateDeviceSnapshot::class, 'snapshot_id');
    }
}
