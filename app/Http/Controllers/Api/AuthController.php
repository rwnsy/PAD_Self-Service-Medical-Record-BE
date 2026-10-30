<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\GoogleLoginRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResendEmailCodeRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\VerifyEmailCodeRequest;
use App\Http\Requests\VerifyPasswordResetCodeRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\GoogleIdTokenVerifier;
use App\Services\VerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, VerificationCodeService $verificationCodes): JsonResponse
    {
        $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);
        $attributes = $request->safe()->only(['name', 'email', 'password', 'phone', 'nik']);

        if (isset($attributes['nik'])) {
            $attributes['nik_hash'] = User::hashNik($attributes['nik']);
        }

        $user = User::query()->create([
            ...$attributes,
            'role_id' => $userRole->role_id,
            'email_verification_pending' => true,
        ]);

        try {
            $verificationCodes->requestCode(
                $user,
                $user->email,
                VerificationCode::EmailVerification,
                $request->ip(),
            );
        } catch (Throwable) {
            $user->delete();

            return $this->failure('Unable to send the verification email. Please try again.', 503);
        }

        return $this->success('Registration started. Check your email for the verification code.', [
            'email' => $user->email,
        ], 202);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! is_string($user->password) || ! Hash::check($credentials['password'], $user->password)) {
            return $this->failure('Email or password is incorrect.', 401);
        }

        if ($user->email_verification_pending) {
            return $this->failure('Email verification is required before you can log in.', 403);
        }

        return $this->authenticated($user, 'Login successful.');
    }

    public function verifyEmail(VerifyEmailCodeRequest $request, VerificationCodeService $verificationCodes): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()
            ->where('email', $validated['email'])
            ->where('email_verification_pending', true)
            ->first();

        if ($user === null) {
            return $this->failure('The verification code is invalid or has already been used.', 422);
        }

        $verificationCodes->consume($user, VerificationCode::EmailVerification, $validated['code']);
        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_pending' => false,
        ])->save();

        return $this->success('Email verified successfully. You can now log in.');
    }

    public function resendEmailCode(ResendEmailCodeRequest $request, VerificationCodeService $verificationCodes): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::query()
            ->where('email', $email)
            ->where('email_verification_pending', true)
            ->first();

        try {
            $retryAfter = $verificationCodes->requestCode(
                $user,
                $email,
                VerificationCode::EmailVerification,
                $request->ip(),
            );
        } catch (Throwable) {
            return $this->failure('Unable to send the verification email. Please try again.', 503);
        }

        if ($retryAfter > 0) {
            return $this->failure('Please wait before requesting another verification code.', 429, [
                'retry_after' => $retryAfter,
            ]);
        }

        return $this->success('If verification is pending, a new code has been sent.');
    }

    public function forgotPassword(ForgotPasswordRequest $request, VerificationCodeService $verificationCodes): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::query()->where('email', $email)->first();
        $passwordUser = $user !== null && is_string($user->password) ? $user : null;

        try {
            $retryAfter = $verificationCodes->requestCode(
                $passwordUser,
                $email,
                VerificationCode::PasswordReset,
                $request->ip(),
            );
        } catch (Throwable) {
            return $this->failure('Unable to process the password reset request. Please try again.', 503);
        }

        if ($retryAfter > 0) {
            return $this->failure('Please wait before requesting another password reset code.', 429, [
                'retry_after' => $retryAfter,
            ]);
        }

        return $this->success('If the email is registered, a password reset code has been sent.');
    }

    public function verifyPasswordResetCode(VerifyPasswordResetCodeRequest $request, VerificationCodeService $verificationCodes): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null || ! is_string($user->password)) {
            return $this->failure('The verification code is invalid or has already been used.', 422);
        }

        $resetToken = $verificationCodes->createPasswordResetGrant($user, $validated['code']);

        return $this->success('Verification code accepted. Set your new password.', [
            'reset_token' => $resetToken,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request, VerificationCodeService $verificationCodes): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null || ! is_string($user->password)) {
            return $this->failure('The password reset token is invalid or has expired.', 422);
        }

        $verificationCodes->completePasswordReset(
            $user,
            $validated['reset_token'],
            function () use ($user, $validated): void {
                $user->forceFill(['password' => $validated['password']])->save();
                $user->tokens()->delete();
            },
        );

        return $this->success('Password reset successfully. Please log in with your new password.');
    }

    public function google(GoogleLoginRequest $request, GoogleIdTokenVerifier $googleIdTokenVerifier): JsonResponse
    {
        try {
            $claims = $googleIdTokenVerifier->verify($request->validated('id_token'));
        } catch (InvalidArgumentException) {
            return $this->failure('The Google ID token is invalid.', 401);
        } catch (RuntimeException) {
            return $this->failure('Google authentication is currently unavailable.', 503);
        }

        $user = DB::transaction(function () use ($claims): ?User {
            $user = User::query()->where('google_sub', $claims['sub'])->lockForUpdate()->first();

            if ($user !== null) {
                return $user;
            }

            $user = User::query()->where('email', Str::lower($claims['email']))->lockForUpdate()->first();

            if ($user !== null) {
                if ($user->email_verified_at === null) {
                    return null;
                }

                $user->forceFill([
                    'google_sub' => $claims['sub'],
                    'email_verification_pending' => false,
                ])->save();

                return $user;
            }

            $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);

            $newUser = new User;

            $newUser->forceFill([
                'role_id' => $userRole->role_id,
                'name' => Str::limit($claims['name'], 255, ''),
                'email' => Str::lower($claims['email']),
                'password' => null,
                'google_sub' => $claims['sub'],
                'email_verified_at' => now(),
                'email_verification_pending' => false,
            ]);
            $newUser->save();

            return $newUser;
        });

        if ($user === null) {
            return $this->failure('An existing account requires secure account linking before Google sign-in.', 409);
        }

        return $this->authenticated($user, 'Google sign-in successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success('Logout successful.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success('Authenticated user retrieved successfully.', $request->user()->load('role'));
    }

    private function authenticated(User $user, string $message): JsonResponse
    {
        $token = $user->createToken('android-api')->plainTextToken;

        return $this->success($message, [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('role'),
        ]);
    }

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function failure(string $message, int $status, mixed $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
