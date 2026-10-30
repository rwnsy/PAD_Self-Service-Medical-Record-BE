<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'purpose',
    'code_hash',
    'attempts',
    'expires_at',
    'used_at',
    'invalidated_at',
    'grant_hash',
    'grant_expires_at',
    'grant_used_at',
])]
#[Hidden(['code_hash', 'grant_hash'])]
class VerificationCode extends Model
{
    public const string EmailVerification = 'EMAIL_VERIFICATION';

    public const string PasswordReset = 'PASSWORD_RESET';

    protected $primaryKey = 'verification_code_id';

    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'grant_expires_at' => 'datetime',
            'grant_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
