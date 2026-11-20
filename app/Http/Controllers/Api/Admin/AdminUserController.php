<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 15), 100);
        $search = $request->query('search');
        $roleFilter = $request->query('role');
        $isActiveFilter = $request->query('is_active');

        $users = User::query()
            ->with('role')
            ->when($search, function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            })
            ->when($roleFilter, function ($query) use ($roleFilter): void {
                $query->whereHas('role', fn ($q) => $q->where('role_name', $roleFilter));
            })
            ->when($isActiveFilter !== null, function ($query) use ($isActiveFilter): void {
                $query->where('is_active', filter_var($isActiveFilter, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // Strip PII — only return safe fields
        $users->getCollection()->transform(fn (User $user) => $this->safeUser($user));

        return response()->json([
            'success' => true,
            'message' => 'User list retrieved successfully.',
            'data' => $users,
        ]);
    }

    public function show(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->with('role')->find($userId);

        if ($user === null) {
            return $this->notFound();
        }

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'ADMIN_USER_VIEW', "Admin viewed user #{$userId} details.");

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data' => $this->safeUser($user),
        ]);
    }

    public function healthRecords(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return $this->notFound();
        }

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'ADMIN_HEALTH_RECORDS_VIEW', "Admin viewed health records for user #{$userId}.");

        $perPage = min((int) $request->query('per_page', 15), 100);

        $records = $user->healthRecords()
            ->orderByDesc('recorded_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Health records retrieved successfully.',
            'data' => $records,
        ]);
    }

    public function personalMedicalRecords(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return $this->notFound();
        }

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'ADMIN_PERSONAL_RECORDS_VIEW', "Admin viewed personal medical records for user #{$userId}.");

        $perPage = min((int) $request->query('per_page', 15), 100);

        $records = $user->personalMedicalRecords()
            ->orderByDesc('visit_date')
            ->orderByDesc('personal_medical_record_id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Personal medical records retrieved successfully.',
            'data' => $records,
        ]);
    }

    public function updateStatus(Request $request, int $userId): JsonResponse
    {
        $admin = $request->user();

        // Admin cannot change their own status
        if ($admin->user_id === $userId) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own account status.',
                'data' => null,
            ], 403);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user = User::query()->with('role')->find($userId);

        if ($user === null) {
            return $this->notFound();
        }

        // If deactivating an ADMIN, ensure at least one other active ADMIN remains
        if ($validated['is_active'] === false && $user->role?->role_name === 'ADMIN') {
            $remainingActiveAdmins = User::query()
                ->whereHas('role', fn ($q) => $q->where('role_name', 'ADMIN'))
                ->where('is_active', true)
                ->where('user_id', '!=', $userId)
                ->count();

            if ($remainingActiveAdmins === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deactivate the last active administrator.',
                    'data' => null,
                ], 422);
            }
        }

        $user->forceFill(['is_active' => $validated['is_active']])->save();

        // On deactivation, revoke all tokens immediately
        if ($validated['is_active'] === false) {
            $user->tokens()->delete();
        }

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $status = $validated['is_active'] ? 'activated' : 'deactivated';
        $activityLog->log($admin->user_id, 'ADMIN_USER_STATUS_CHANGE', "Admin {$status} user #{$userId}.");

        return response()->json([
            'success' => true,
            'message' => "User account {$status} successfully.",
            'data' => $this->safeUser($user->fresh('role')),
        ]);
    }

    public function sessions(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return $this->notFound();
        }

        $tokens = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => $this->safeToken($token));

        return response()->json([
            'success' => true,
            'message' => 'Sessions retrieved successfully.',
            'data' => $tokens,
        ]);
    }

    public function revokeSession(Request $request, int $userId, int $tokenId): JsonResponse
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return $this->notFound();
        }

        $token = $user->tokens()->where('id', $tokenId)->first();

        if ($token === null) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found.',
                'data' => null,
            ], 404);
        }

        $token->delete();

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'ADMIN_SESSION_REVOKE', "Admin revoked session #{$tokenId} for user #{$userId}.");

        return response()->json([
            'success' => true,
            'message' => 'Session revoked successfully.',
            'data' => null,
        ]);
    }

    public function revokeAllSessions(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return $this->notFound();
        }

        $count = $user->tokens()->count();
        $user->tokens()->delete();

        /** @var ActivityLogService $activityLog */
        $activityLog = app(ActivityLogService::class);
        $activityLog->log($request->user()->user_id, 'ADMIN_SESSION_REVOKE_ALL', "Admin revoked all {$count} sessions for user #{$userId}.");

        return response()->json([
            'success' => true,
            'message' => "All {$count} session(s) revoked successfully.",
            'data' => null,
        ]);
    }

    /**
     * Return only safe, non-PII fields for a user.
     *
     * @return array<string, mixed>
     */
    private function safeUser(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'name' => $user->name,
            'email' => $user->email,
            'gender' => $user->gender,
            'date_of_birth' => $user->date_of_birth,
            'avatar_url' => $user->avatar_url,
            'email_verified_at' => $user->email_verified_at,
            'email_verification_pending' => $user->email_verification_pending,
            'is_active' => $user->is_active,
            'last_seen_at' => $user->last_seen_at,
            'role' => $user->role?->role_name,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeToken(PersonalAccessToken $token): array
    {
        return [
            'token_id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'created_at' => $token->created_at,
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'User not found.',
            'data' => null,
        ], 404);
    }
}
