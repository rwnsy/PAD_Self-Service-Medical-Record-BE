<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['component', 'status', 'response_time_ms', 'metadata', 'checked_at'])]
class SystemMetric extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'checked_at' => 'datetime',
            'response_time_ms' => 'integer',
        ];
    }
}
