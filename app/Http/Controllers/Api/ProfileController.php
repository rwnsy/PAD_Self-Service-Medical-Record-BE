<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\VerificationCodeService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, VerificationCodeService $verificationCodes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;

        if (isset($validated['nik'])) {
            $validated['nik_hash'] = User::hashNik($validated['nik']);
        }

        if ($emailChanged) {
            $validated['email_verified_at'] = null;
            $validated['email_verification_pending'] = true;
        }

        $user->forceFill($validated)->save();

        if ($emailChanged) {
            try {
                $retryAfter = $verificationCodes->requestCode(
                    $user,
                    $user->email,
                    VerificationCode::EmailVerification,
                    $request->ip(),
                );
            } catch (Throwable) {
                return $this->failure('Profile updated, but the verification email could not be sent. Please resend the code.', 503);
            }

            if ($retryAfter > 0) {
                return $this->success('Profile updated. Please wait before requesting an email verification code.', [
                    'user' => $user->fresh(),
                    'retry_after' => $retryAfter,
                ]);
            }

            return $this->success('Profile updated. Verify the new email address to complete the change.', $user->fresh());
        }

        return $this->success('Profile updated successfully.', $user->fresh());
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
