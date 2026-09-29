<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $fillable = [
        'ticket_type_id',
        'quantity',
        'status',
        'token',
        'expires_at',
    ];

    public function ticketType()
    {
        return $this->belongsTo(TicketType::class);
    }
}
