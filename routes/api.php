<?php

use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminIntegrationLogController;
use App\Http\Controllers\Api\Admin\AdminLogController;
use App\Http\Controllers\Api\Admin\AdminNotificationController;
use App\Http\Controllers\Api\Admin\AdminOpenMrsController;
use App\Http\Controllers\Api\Admin\AdminSystemLogController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\CalorieEstimationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthRecordController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\PersonalMedicalRecordController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/avatar/default', [AvatarController::class, 'default'])->name('avatar.default');
Route::get('/calorie-estimation/image/{filename}', [CalorieEstimationController::class, 'showImage'])
    ->name('calorie-estimation.image')
    ->middleware('signed');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/email/verify', [AuthController::class, 'verifyEmail']);
Route::post('/email/resend-code', [AuthController::class, 'resendEmailCode']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/verify-code', [AuthController::class, 'verifyPasswordResetCode']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);
Route::post('/auth/google', [AuthController::class, 'google']);

Route::middleware(['auth:sanctum', 'account.active', 'last.seen'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::match(['put', 'patch'], '/profile', [ProfileController::class, 'update']);
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);
    Route::apiResource('health-records', HealthRecordController::class)
        ->parameters(['health-records' => 'healthRecordId']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/medical-records', [MedicalRecordController::class, 'index']);
    Route::apiResource('personal-medical-records', PersonalMedicalRecordController::class)
        ->parameters(['personal-medical-records' => 'personalMedicalRecordId']);
    Route::post('/calorie-estimation', [CalorieEstimationController::class, 'estimate']);

    Route::middleware('role:ADMIN')->prefix('admin')->group(function (): void {
        // Dashboard
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])
            ->middleware('throttle:admin-dashboard');

        // User Management
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{userId}', [AdminUserController::class, 'show']);
        Route::patch('/users/{userId}/status', [AdminUserController::class, 'updateStatus']);
        Route::get('/users/{userId}/health-records', [AdminUserController::class, 'healthRecords']);
        Route::get('/users/{userId}/personal-medical-records', [AdminUserController::class, 'personalMedicalRecords']);

        // Session Management
        Route::get('/users/{userId}/sessions', [AdminUserController::class, 'sessions']);
        Route::delete('/users/{userId}/sessions/{tokenId}', [AdminUserController::class, 'revokeSession']);
        Route::delete('/users/{userId}/sessions', [AdminUserController::class, 'revokeAllSessions']);

        // Logs
        Route::get('/activity-logs', [AdminActivityLogController::class, 'index']);
        Route::get('/integration-logs', [AdminIntegrationLogController::class, 'index']);
        Route::get('/system-logs', [AdminSystemLogController::class, 'index'])
            ->middleware('throttle:admin-system-logs');
        Route::get('/logs', [AdminLogController::class, 'index']);

        // OpenMRS
        Route::get('/openmrs/config', [AdminOpenMrsController::class, 'getConfig']);
        Route::put('/openmrs/config', [AdminOpenMrsController::class, 'updateConfig']);
        Route::post('/openmrs/test-connection', [AdminOpenMrsController::class, 'testConnection'])
            ->middleware('throttle:admin-test-connection');

        // Notifications
        Route::get('/notifications', [AdminNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [AdminNotificationController::class, 'unreadCount']);
        Route::patch('/notifications/{notificationId}/read', [AdminNotificationController::class, 'markAsRead']);
        Route::patch('/notifications/read-all', [AdminNotificationController::class, 'markAllAsRead']);
    });
});
