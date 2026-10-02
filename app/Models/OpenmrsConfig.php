<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['endpoint_url', 'credential', 'is_active', 'updated_by'])]
#[Hidden(['credential'])]
class OpenmrsConfig extends Model
{
    protected $primaryKey = 'config_id';

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }
}
