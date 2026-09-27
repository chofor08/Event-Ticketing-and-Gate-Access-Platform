<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRequest extends Model
{
    protected $fillable = [
        'order_id',
        'event_id',
        'reason',
        'order_item_ids',
        'amount_cents',
        'idempotency_key',
        'attempts',
        'stripe_refund_id',
        'status',
        'last_error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_item_ids' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
