<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Halaman pengujian developer / pratinjau hanya diizinkan di environment non-production (local / testing)
Route::middleware('non-production')->group(function (): void {
    Route::view('/google-login-test', 'google-login-test');
    Route::view('/calorie-estimation', 'calorie-estimation');
    Route::view('/profile-photo-test', 'profile-photo');
});
