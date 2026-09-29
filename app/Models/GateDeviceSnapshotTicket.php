<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateDeviceSnapshotTicket extends Model
{
    protected $fillable = ['snapshot_id', 'ticket_id', 'code_hash', 'ticket_status'];

    protected $hidden = ['code_hash'];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(GateDeviceSnapshot::class, 'snapshot_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
