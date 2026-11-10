<?php

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

Route::middleware('auth:sanctum')->group(function (): void {
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
});
