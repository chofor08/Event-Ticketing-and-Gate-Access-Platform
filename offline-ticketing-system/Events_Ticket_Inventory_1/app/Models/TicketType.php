<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class TicketType extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'base_price',
        'discount',
        'quantity',
    ];

    protected $appends = ['price'];

    protected function price(): Attribute
    {
        return Attribute::get(function (): int {
            $basePrice = (int) $this->base_price;
            $discount = (int) ($this->discount ?? 0);

            return (int) round($basePrice * (100 - $discount) / 100);
        });
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function holds()
    {
        return $this->hasMany(Hold::class);
    }
}
