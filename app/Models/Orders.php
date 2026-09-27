<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orders extends Model
{
    protected $fillable = [
        'user_id',
        'payment_intent_id',
        'session_id',
        'currency',
        'payment_method_type',
        'payment_method_id',
        'payment_method_brand',
        'payment_method_last4',
        'payment_method_details',
        'status',
        'amount_cents',
    ];

    protected function casts(): array
    {
        return [
            'payment_method_details' => 'array',
        ];
    }

    public function getPaymentMethodDisplayAttribute(): string
    {
        if (! $this->payment_method_type) {
            return 'Not provided';
        }

        $method = $this->payment_method_type === 'card' && $this->payment_method_brand
            ? ucfirst($this->payment_method_brand)
            : ucwords(str_replace('_', ' ', $this->payment_method_type));

        return $this->payment_method_last4
            ? $method.' ending in '.$this->payment_method_last4
            : $method;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItems::class, 'order_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntries::class, 'order_id');
    }
}
