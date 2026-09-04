<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserSession;
use App\Models\UserToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log; // Added for temporary Postman token extraction
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    protected const MAX_FAILED_ATTEMPTS = 5;
    protected const LOCKOUT_MINUTES = 15;
    protected const EMAIL_VERIFICATION_TTL_HOURS = 24;
    protected const PASSWORD_RESET_TTL_MINUTES = 60;
    protected const REFRESH_TOKEN_TTL_DAYS = 30;

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $user = User::create([
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'role' => 'customer',
            ]);

            $this->logAudit($user->id, 'create', 'user', $user->id, null, [
                'email' => $user->email,
            ]);

            $verificationToken = $this->issueUserToken($user, 'email_verification', self::EMAIL_VERIFICATION_TTL_HOURS * 60);

            // Logs the raw string to storage/logs/laravel.log so you can copy it for Postman testing
            Log::info("NEW " . $user->email . " VERIFICATION TOKEN: " . $verificationToken);

            // Returning 201 Created without issuing JWT/Refresh tokens to enforce strict verification
            return response()->json([
                'message' => 'User registered successfully. Please check your email for the verification token.',
                'user' => $user
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            // Catch duplicate entry key violation (SQL state 23000 or MySQL code 1062)
            if ($e->getCode() == 23000 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062)) {
                return response()->json([
                    'message' => 'User already exists with this email address.'
                ], 409); // 409 Conflict
            }

            Log::error("Database error during registration: " . $e->getMessage());

            return response()->json([
                'message' => 'Registration failed due to a database error.'
            ], 500);

        } catch (\Throwable $e) {
            Log::error("Registration failed: " . $e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred during registration.'
            ], 500);
        }
    }
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        // 1. Check if email exists
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Email address not found.'],
            ]);
        }

        // 2. Lockout Check (MUST run before password check)
        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw ValidationException::withMessages([
                'email' => ["Account is locked until {$user->locked_until->toDateTimeString()}."],
            ]);
        }

        // 3. Password Verification
        if (!Hash::check($credentials['password'], $user->password_hash)) {
            $this->registerFailedLogin($user);

            // Check if THIS specific failed attempt triggered a lockout
            $user->refresh();
            if ($user->locked_until && $user->locked_until->isFuture()) {
                throw ValidationException::withMessages([
                    'email' => ["Account is locked until {$user->locked_until->toDateTimeString()}."],
                ]);
            }

            throw ValidationException::withMessages([
                'password' => ['Incorrect password.'],
            ]);
        }

        // 4. Verification Check
        if (is_null($user->email_verified_at)) {
            return response()->json([
                'message' => 'Please verify your email address before logging in.',
            ], 403);
        }

        // Successful login: clear lockout state
        if ($user->failed_login_attempts > 0 || $user->locked_until) {
            $user->update([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
        }

        $token = JWTAuth::fromUser($user);
        $session = $this->createSession($user, $request);

        $this->logAudit($user->id, 'login', 'user', $user->id);

        return $this->respondWithToken($token, $session['refresh_token'], $user, 'Login successful.');
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'user' => auth('api')->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        $refreshToken = $request->input('refresh_token');

        if (is_string($refreshToken) && filled($refreshToken)) {
            UserSession::where('token_hash', hash('sha256', $refreshToken))->delete();
        }

        try {
            JWTAuth::invalidate();
        } catch (\Exception $e) {
            // If token invalidation fails (e.g., no token present), continue logout
            Log::warning('Token invalidation failed during logout', [
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($user) {
            $this->logAudit($user->id, 'logout', 'user', $user->id);
        }

        return response()->json([
            'message' => 'User logged out successfully.',
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->input('refresh_token');

        if (!is_string($refreshToken) || blank($refreshToken)) {
            return response()->json([
                'message' => 'Refresh token is required.',
            ], 422);
        }

        $session = UserSession::where('token_hash', hash('sha256', $refreshToken))
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            return response()->json([
                'message' => 'Invalid or expired refresh token.',
            ], 401);
        }

        $user = $session->user;

        if (!$user || ($user->locked_until && $user->locked_until->isFuture())) {
            return response()->json([
                'message' => 'Account is unavailable.',
            ], 403);
        }

        $session->delete();
        $newSession = $this->createSession($user, $request);

        $token = JWTAuth::fromUser($user);

        return $this->respondWithToken($token, $newSession['refresh_token'], $user, 'Token refreshed successfully.');
    }

    public function verifyEmail(): JsonResponse
    {
        $token = request()->input('token');

        if (!is_string($token) || blank($token)) {
            return response()->json([
                'message' => 'Verification token is required.',
            ], 422);
        }

        $userToken = UserToken::where('token_hash', hash('sha256', $token))
            ->where('type', 'email_verification')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$userToken) {
            return response()->json([
                'message' => 'Invalid or expired verification token.',
            ], 400);
        }

        $userToken->user->update([
            'email_verified_at' => now(),
        ]);

        $userToken->update([
            'used_at' => now(),
        ]);

        $this->logAudit($userToken->user_id, 'update', 'user', $userToken->user_id, null, [
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        return response()->json([
            'message' => 'Email verified successfully.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->input('email'))->first();

        if ($user) {
            $resetToken = $this->issueUserToken($user, 'password_reset', self::PASSWORD_RESET_TTL_MINUTES);

            Log::info("PASSWORD RESET TOKEN: " . $resetToken);

            $this->logAudit($user->id, 'password_reset_requested', 'user', $user->id);
        }

        return response()->json([
            'message' => 'If an account with that email exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $userToken = UserToken::where('token_hash', hash('sha256', $validated['token']))
            ->where('type', 'password_reset')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$userToken) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
            ], 400);
        }

        $user = $userToken->user;

        $user->update([
            'password_hash' => Hash::make($validated['password']),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        $userToken->update(['used_at' => now()]);

        UserSession::where('user_id', $user->id)->delete();

        $this->logAudit($user->id, 'password_reset', 'user', $user->id);

        return response()->json([
            'message' => 'Password reset successfully. Please log in again.',
        ]);
    }

    /**
     * Replaced in-memory attempt counting with atomic increments to prevent race conditions.
     */
    protected function registerFailedLogin(User $user): void
    {
        $user->increment('failed_login_attempts');
        $user->refresh();

        $attempts = $user->failed_login_attempts;
        $update = [];

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $update['locked_until'] = now()->addMinutes(self::LOCKOUT_MINUTES);
            $user->update($update);
        }

        $this->logAudit($user->id, 'login_failed', 'user', $user->id, null, [
            'failed_login_attempts' => $attempts,
        ]);
    }

    protected function issueUserToken(User $user, string $type, int $ttlMinutes): string
    {
        $raw = Str::random(64);

        UserToken::create([
            'user_id' => $user->id,
            'type' => $type,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $raw;
    }

    protected function createSession(User $user, Request $request): array
    {
        $raw = Str::random(64);

        $session = UserSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $raw),
            'device_info' => substr((string) $request->userAgent(), 0, 255),
            'ip_address' => $request->ip(),
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        ]);

        return ['session' => $session, 'refresh_token' => $raw];
    }

    protected function logAudit(
        ?int $userId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    protected function respondWithToken(
        string $token,
        ?string $refreshToken = null,
        ?User $user = null,
        string $message = 'Authenticated successfully.',
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
            'user' => $user ?? auth('api')->user(),
        ], $status);
    }
}