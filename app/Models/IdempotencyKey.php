<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'status',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
        ];
    }
}
