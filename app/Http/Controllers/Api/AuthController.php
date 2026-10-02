<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $userRole = Role::query()->firstOrCreate(['role_name' => 'USER']);

        $user = User::query()->create([
            ...$request->safe()->only(['name', 'email', 'password', 'phone', 'nik']),
            'role_id' => $userRole->role_id,
        ]);

        return $this->success('User registered successfully.', $user->load('role'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return $this->failure('Email or password is incorrect.', 401);
        }

        $token = $user->createToken('android-api')->plainTextToken;

        return $this->success('Login successful.', [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('role'),
        ]);
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

    private function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $status);
    }
}
