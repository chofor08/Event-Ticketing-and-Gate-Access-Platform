<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItems extends Model
{
    protected $fillable = [
        'order_id',
        'ticket_type_id',
        'hold_id',
        'quantity',
        'unit_price_xaf',
        'sub_total_xaf',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'order_item_id');
    }
}
