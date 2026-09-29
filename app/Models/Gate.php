<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gate extends Model
{
    protected $fillable = ['event_id', 'name', 'status'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(GateDevice::class);
    }

    public function scanAttempts(): HasMany
    {
        return $this->hasMany(ScanAttempt::class);
    }
}
