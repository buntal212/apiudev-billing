<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password) || ! $user->is_admin) {
            return response()->json([
                'message' => 'Username atau kata sandi yang Anda masukkan salah.',
                'errors' => [
                    'username' => ['Username atau kata sandi yang Anda masukkan salah.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $expiresAt = now()->addMinutes(config('auth.admin_token_expiration'));
        $token = $user->createToken('udev-admin', ['admin:access'], $expiresAt);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toISOString(),
                'user' => new AuthUserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(status: 204);
    }

    public function me(Request $request): AuthUserResource
    {
        return new AuthUserResource($request->user());
    }
}
