<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'operation',
    'status',
    'request_data',
    'response_data',
    'error_message',
    'attempted_at',
])]
#[Hidden(['request_data', 'response_data', 'error_message'])]
class IntegrationLog extends Model
{
    protected $primaryKey = 'log_id';

    protected function casts(): array
    {
        return [
            'request_data' => 'array',
            'response_data' => 'array',
            'attempted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
