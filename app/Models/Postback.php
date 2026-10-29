<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Postback extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'identity_hash',
        'click_id',
        'event',
        'original_amount',
        'amount',
        'received_at',
        'status',
        'attempts',
        'last_http_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'last_http_status' => 'integer',
        ];
    }
}
