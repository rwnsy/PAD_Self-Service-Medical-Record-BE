<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['role_id', 'name', 'email', 'password', 'phone', 'nik', 'nik_hash', 'gender', 'date_of_birth', 'google_sub', 'email_verification_pending'])]
#[Hidden(['password', 'remember_token', 'phone', 'nik', 'nik_hash', 'google_sub'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'phone' => 'encrypted',
            'nik' => 'encrypted',
            'email_verification_pending' => 'boolean',
            'date_of_birth' => 'date:Y-m-d',
        ];
    }

    public static function hashNik(string $nik): string
    {
        return hash_hmac('sha256', $nik, (string) config('app.key'));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function healthRecords(): HasMany
    {
        return $this->hasMany(HealthRecord::class, 'user_id', 'user_id');
    }

    public function personalMedicalRecords(): HasMany
    {
        return $this->hasMany(PersonalMedicalRecord::class, 'user_id', 'user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'user_id', 'user_id');
    }

    public function integrationLogs(): HasMany
    {
        return $this->hasMany(IntegrationLog::class, 'user_id', 'user_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'user_id', 'user_id');
    }

    public function updatedOpenmrsConfigs(): HasMany
    {
        return $this->hasMany(OpenmrsConfig::class, 'updated_by', 'user_id');
    }

    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class, 'user_id', 'user_id');
    }
}
