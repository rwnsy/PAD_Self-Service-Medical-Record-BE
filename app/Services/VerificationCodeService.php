<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Models\VerificationCode;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerificationCodeService
{
    private const int CodeExpiryMinutes = 10;

    private const int GrantExpiryMinutes = 10;

    private const int MaximumAttempts = 5;

    private const int ResendCooldownSeconds = 60;

    public function requestCode(?User $user, string $email, string $purpose, ?string $ipAddress): int
    {
        $rateLimitKey = $this->rateLimitKey($email, $purpose, $ipAddress);

        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            return RateLimiter::availableIn($rateLimitKey);
        }

        RateLimiter::hit($rateLimitKey, self::ResendCooldownSeconds);

        if ($user === null) {
            return 0;
        }

        $plainCode = (string) random_int(100000, 999999);
        $verificationCode = DB::transaction(function () use ($user, $purpose, $plainCode): VerificationCode {
            $user->verificationCodes()
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            return $user->verificationCodes()->create([
                'purpose' => $purpose,
                'code_hash' => Hash::make($plainCode),
                'expires_at' => now()->addMinutes(self::CodeExpiryMinutes),
            ]);
        });

        try {
            Mail::to($user->email)->send(new VerificationCodeMail($plainCode, $purpose));
        } catch (Throwable $throwable) {
            $verificationCode->forceFill(['invalidated_at' => now()])->save();
            RateLimiter::clear($rateLimitKey);

            throw $throwable;
        }

        return 0;
    }

    public function consume(
        User $user,
        string $purpose,
        string $plainCode
    ): VerificationCode {
        $result = DB::transaction(function () use (
            $user,
            $purpose,
            $plainCode
        ): array {
            $verificationCode = $user->verificationCodes()
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->latest('verification_code_id')
                ->lockForUpdate()
                ->first();

            if ($verificationCode === null) {
                return ['error' => 'invalid'];
            }

            if (
                $verificationCode->expires_at === null ||
                $verificationCode->expires_at->isPast()
            ) {
                $verificationCode->forceFill([
                    'invalidated_at' => now(),
                ])->save();

                return ['error' => 'expired'];
            }

            if (! Hash::check($plainCode, $verificationCode->code_hash)) {
                $attempts = $verificationCode->attempts + 1;

                $verificationCode->forceFill([
                    'attempts' => $attempts,
                    'invalidated_at' => $attempts >= self::MaximumAttempts
                        ? now()
                        : null,
                ])->save();

                return [
                    'error' => $attempts >= self::MaximumAttempts
                        ? 'max_attempts'
                        : 'incorrect',
                ];
            }

            $verificationCode->forceFill([
                'used_at' => now(),
            ])->save();

            return ['code' => $verificationCode];
        });

        if (isset($result['error'])) {
            $message = match ($result['error']) {
                'expired' => 'The verification code has expired.',
                'incorrect' => 'The verification code is incorrect.',
                'max_attempts' => 'The verification code has reached its maximum number of attempts.',
                default => 'The verification code is invalid or has already been used.',
            };

            throw ValidationException::withMessages([
                'code' => [$message],
            ]);
        }

        return $result['code'];
    }

    public function createPasswordResetGrant(User $user, string $plainCode): string
    {
        $verificationCode = $this->consume($user, VerificationCode::PasswordReset, $plainCode);
        $resetToken = Str::random(64);

        $verificationCode->forceFill([
            'grant_hash' => Hash::make($resetToken),
            'grant_expires_at' => now()->addMinutes(self::GrantExpiryMinutes),
        ])->save();

        return $resetToken;
    }

    public function completePasswordReset(User $user, string $resetToken, Closure $resetPassword): void
    {
        DB::transaction(function () use ($user, $resetToken, $resetPassword): void {
            $verificationCode = $user->verificationCodes()
                ->where('purpose', VerificationCode::PasswordReset)
                ->whereNotNull('grant_hash')
                ->whereNull('grant_used_at')
                ->latest('verification_code_id')
                ->lockForUpdate()
                ->first();

            if ($verificationCode === null
                || $verificationCode->grant_expires_at === null
                || $verificationCode->grant_expires_at->isPast()
                || ! Hash::check($resetToken, $verificationCode->grant_hash)) {
                throw ValidationException::withMessages([
                    'reset_token' => ['The password reset token is invalid or has expired.'],
                ]);
            }

            $resetPassword();

            $verificationCode->forceFill(['grant_used_at' => now()])->save();
        });
    }

    private function rateLimitKey(string $email, string $purpose, ?string $ipAddress): string
    {
        return 'verification-code:'.$purpose.':'.hash('sha256', Str::lower($email).'|'.$ipAddress);
    }
}
