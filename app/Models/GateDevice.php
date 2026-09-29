<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GateDevice extends Model
{
    protected $fillable = ['public_id', 'gate_id', 'user_id', 'name', 'last_snapshot_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'last_snapshot_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(GateDeviceSnapshot::class, 'device_id');
    }

    public function scanAttempts(): HasMany
    {
        return $this->hasMany(ScanAttempt::class, 'device_id');
    }
}
