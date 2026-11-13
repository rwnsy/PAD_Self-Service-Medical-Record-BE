<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 15), 100);
        $search = $request->query('search');
        $roleFilter = $request->query('role');

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
            'role' => $user->role?->role_name,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
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
