<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * User authentication & Sanctum token issue.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $user = User::with('affiliate')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak cocok dengan data kami.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda dinonaktifkan. Silakan hubungi Administrator.',
            ], 403);
        }

        $user->update(['last_login_at' => Carbon::now()]);

        $deviceName = $request->device_name ?? 'RentCore Mobile Terminal';
        $token = $user->createToken($deviceName)->plainTextToken;

        $memberships = \App\Models\BusinessMember::with('business.branches')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $businesses = $memberships->map(function ($m) {
            return [
                'id' => $m->business->id,
                'uuid' => $m->business->uuid,
                'name' => $m->business->name,
                'role' => $m->role,
                'branches' => $m->serializeAccessibleBranches(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'affiliate' => $user->affiliate ? [
                        'id' => $user->affiliate->id,
                        'code' => $user->affiliate->code,
                        'name' => $user->affiliate->name,
                        'city' => $user->affiliate->city,
                        'is_central' => $user->affiliate->is_central,
                    ] : null,
                ],
                'businesses' => $businesses,
            ],
        ]);
    }

    /**
     * Google OAuth 2.0 Sign-In & Sanctum token issue.
     */
    public function googleSignIn(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $clientId = config('services.google.client_id');
        $idToken = $request->id_token;
        $googleUser = null;

        // Verify with Google API Client if real token
        if (!empty($clientId)) {
            try {
                $client = new \Google_Client(['client_id' => $clientId]);
                $payload = $client->verifyIdToken($idToken);
                if ($payload) {
                    $aud = $payload['aud'] ?? '';
                    $iss = $payload['iss'] ?? '';
                    $exp = $payload['exp'] ?? 0;

                    // Verify audience matches configured Google Client ID, issuer is valid, and token is not expired
                    if ($aud === $clientId &&
                        in_array($iss, ['accounts.google.com', 'https://accounts.google.com']) &&
                        $exp > time() &&
                        !empty($payload['sub']) &&
                        !empty($payload['email'])
                    ) {
                        $googleUser = [
                            'sub' => (string) $payload['sub'],
                            'email' => (string) $payload['email'],
                            'name' => (string) ($payload['name'] ?? explode('@', $payload['email'])[0]),
                            'avatar' => $payload['picture'] ?? null,
                            'email_verified' => (bool) ($payload['email_verified'] ?? true),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Google ID Token verification failed: ' . $e->getMessage());
            }
        }

        // Automated test mock support (strictly for PHPUnit test runner)
        if (!$googleUser && app()->environment('testing') && str_starts_with($idToken, 'dev_token_')) {
            $email = $request->get('email', 'dev.user@rentcore.id');
            $googleUser = [
                'sub' => 'google_sub_' . md5($email),
                'email' => $email,
                'name' => $request->get('name', 'Developer Test User'),
                'avatar' => null,
                'email_verified' => true,
            ];
        }

        if (!$googleUser) {
            if (empty($clientId) && !app()->environment('testing')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Konfigurasi Google Client ID belum diatur di server backend (.env GOOGLE_CLIENT_ID).',
                    'code' => 'GOOGLE_AUTH_NOT_CONFIGURED',
                ], 500);
            }

            return response()->json([
                'success' => false,
                'message' => 'Token autentikasi Google tidak valid atau telah kedaluwarsa.',
                'code' => 'INVALID_GOOGLE_TOKEN',
            ], 401);
        }

        // Account Resolution & Safe Account Linking Protection
        // 1. Identify user by Google's stable subject identifier (sub)
        $user = User::where('google_id', $googleUser['sub'])->first();

        if (!$user) {
            // 2. Check if an account already exists with the matching email
            $existingUser = User::where('email', $googleUser['email'])->first();

            if ($existingUser) {
                // If user has a password or a different google_id, DO NOT silently link to prevent account hijacking
                if ($existingUser->password !== null || ($existingUser->google_id !== null && $existingUser->google_id !== $googleUser['sub'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Email ini sudah terdaftar dengan kata sandi. Silakan masuk dengan email dan kata sandi Anda.',
                        'code' => 'EMAIL_ALREADY_REGISTERED_WITH_PASSWORD',
                    ], 409);
                }

                // If user was created without a password (e.g. pre-registered/invited), link the verified Google ID
                $existingUser->update([
                    'google_id' => $googleUser['sub'],
                    'avatar_url' => $googleUser['avatar'] ?? $existingUser->avatar_url,
                    'last_login_at' => Carbon::now(),
                ]);
                $user = $existingUser;
            } else {
                // 3. New user onboarding: create user with staff default role and NO platform-admin privileges
                $user = User::create([
                    'email' => $googleUser['email'],
                    'google_id' => $googleUser['sub'],
                    'name' => $googleUser['name'],
                    'avatar_url' => $googleUser['avatar'],
                    'password' => null,
                    'role' => 'staff',
                    'is_active' => true,
                    'is_platform_admin' => false,
                    'last_login_at' => Carbon::now(),
                ]);
            }
        } else {
            // Existing user with matching Google ID: update profile metadata safely
            $user->update([
                'avatar_url' => $googleUser['avatar'] ?? $user->avatar_url,
                'last_login_at' => Carbon::now(),
            ]);
        }

        // Check account active status
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda dinonaktifkan. Silakan hubungi Administrator.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        $deviceName = $request->device_name ?? 'RentCore Mobile Terminal (Google)';
        $token = $user->createToken($deviceName)->plainTextToken;

        $memberships = \App\Models\BusinessMember::with('business.branches')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $businesses = $memberships->map(function ($m) {
            return [
                'id' => $m->business->id,
                'uuid' => $m->business->uuid,
                'name' => $m->business->name,
                'role' => $m->role,
                'branches' => $m->serializeAccessibleBranches(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Login Google berhasil.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'is_platform_admin' => (bool) $user->is_platform_admin,
                ],
                'businesses' => $businesses,
            ],
        ]);
    }

    /**
     * Get authenticated user profile and memberships.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('affiliate');

        $memberships = \App\Models\BusinessMember::with('business.branches')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $businesses = $memberships->map(function ($m) {
            return [
                'id' => $m->business->id,
                'uuid' => $m->business->uuid,
                'name' => $m->business->name,
                'role' => $m->role,
                'branches' => $m->serializeAccessibleBranches(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'businesses' => $businesses,
            ],
        ]);
    }

    /**
     * Terminate current token session.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesi berhasil diakhiri.',
        ]);
    }
}
