<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'base_price_xaf',
        'discount',
        'quantity',
    ];

    protected $appends = ['price_xaf'];

    public function getPriceXafAttribute(): int
    {
        return (int) round($this->base_price_xaf * (100 - $this->discount) / 100);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItems::class);
    }
}
