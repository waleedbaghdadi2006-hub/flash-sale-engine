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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * ASSUMPTIONS / DEPENDENCIES
 * --------------------------
 * These were not verifiable from AuthController.php or SCHEMA.md alone.
 * Confirm each against the real codebase before deploying.
 *
 * 1. User model casts:
 *    App\Models\User must cast 'locked_until' and 'email_verified_at' to
 *    'datetime' (e.g. via $casts or attribute casting). This code calls
 *    ->isFuture() on $user->locked_until, which requires a Carbon instance,
 *    not a raw string.
 *
 * 2. Unverified users can still obtain a token at registration:
 *    register() issues an access_token + refresh_token immediately, even
 *    though the account is unverified, so the user can use the app right
 *    away. login() on a *later* session, however, is blocked with a 403
 *    until email_verified_at is set. This is a deliberate but debatable
 *    choice — if you want zero access before verification, block token
 *    issuance in register() too.
 *
 * 3. Email delivery is out of scope here:
 *    Two TODOs (register(), forgotPassword()) mark where a Mailable /
 *    Notification must be dispatched with the RAW token. No mailer class
 *    existed in the code I was given, so none was assumed or fabricated.
 *    The raw token is only ever available at the moment issueUserToken()
 *    returns it — only its SHA-256 hash is persisted.
 *
 * 4. Routes are not included:
 *    forgotPassword() and resetPassword() are new methods with no
 *    corresponding routes/*.php entries added, since the routes file
 *    wasn't provided. Wire them up (typically POST /forgot-password and
 *    POST /reset-password).
 *
 * 5. Lockout/token lifetimes are guesses, not requirements:
 *    5 failed attempts -> 15 min lockout, 24h email-verification tokens,
 *    60 min password-reset tokens, 30-day refresh tokens. Adjust the
 *    class constants below to match your actual security policy.
 *
 * 6. UserSession model naming:
 *    The sessions table is mapped to a new App\Models\UserSession model
 *    (not "Session") to avoid colliding with Laravel's built-in Session
 *    facade/class. Update the `use` import if you name it differently.
 *
 */
class AuthController extends Controller
{
    /** Max failed attempts before locking the account. */
    protected const MAX_FAILED_ATTEMPTS = 5;

    /** Lockout duration in minutes once the threshold is hit. */
    protected const LOCKOUT_MINUTES = 15;

    /** Email verification token lifetime in hours. */
    protected const EMAIL_VERIFICATION_TTL_HOURS = 24;

    /** Password reset token lifetime in minutes. */
    protected const PASSWORD_RESET_TTL_MINUTES = 60;

    /** Refresh token (session) lifetime in days. */
    protected const REFRESH_TOKEN_TTL_DAYS = 30;

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

        // TODO: dispatch a notification/mailable carrying $verificationToken (the RAW,
        // unhashed value), e.g.:
        //   Mail::to($user)->send(new VerifyEmailMail($verificationToken));
        // Only the SHA-256 hash of this token is persisted in user_tokens, so it
        // must be captured here — it cannot be recovered later.

        $token = JWTAuth::fromUser($user);
        $session = $this->createSession($user, $request);

        return $this->respondWithToken(
            $token,
            $session['refresh_token'],
            $user,
            'User registered successfully. Please verify your email address.',
            201
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            if ($user) {
                $this->registerFailedLogin($user);
            }

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials provided.'],
            ]);
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw ValidationException::withMessages([
                'email' => ["Account is locked until {$user->locked_until->toDateTimeString()}."],
            ]);
        }

        if (is_null($user->email_verified_at)) {
            return response()->json([
                'message' => 'Please verify your email address before logging in.',
            ], 403);
        }

        // Successful login: clear any lockout state.
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

        JWTAuth::logout();

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

        if (! is_string($refreshToken) || blank($refreshToken)) {
            return response()->json([
                'message' => 'Refresh token is required.',
            ], 422);
        }

        $session = UserSession::where('token_hash', hash('sha256', $refreshToken))
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Invalid or expired refresh token.',
            ], 401);
        }

        $user = $session->user;

        if (! $user || ($user->locked_until && $user->locked_until->isFuture())) {
            return response()->json([
                'message' => 'Account is unavailable.',
            ], 403);
        }

        // Rotate the refresh token: delete the one just used and issue a new one,
        // so a captured refresh token is only ever usable once.
        $session->delete();
        $newSession = $this->createSession($user, $request);

        $token = JWTAuth::fromUser($user);

        return $this->respondWithToken($token, $newSession['refresh_token'], $user, 'Token refreshed successfully.');
    }

    public function verifyEmail(): JsonResponse
    {
        $token = request()->input('token');

        if (! is_string($token) || blank($token)) {
            return response()->json([
                'message' => 'Verification token is required.',
            ], 422);
        }

        $userToken = UserToken::where('token_hash', hash('sha256', $token))
            ->where('type', 'email_verification')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $userToken) {
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

        // Always return the same generic response, whether or not the email
        // exists, so this endpoint can't be used to enumerate accounts.
        if ($user) {
            $resetToken = $this->issueUserToken($user, 'password_reset', self::PASSWORD_RESET_TTL_MINUTES);

            // TODO: dispatch a notification/mailable carrying $resetToken (raw value).
            // Only the SHA-256 hash is persisted in user_tokens.

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

        if (! $userToken) {
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

        // Invalidate every active session so a leaked/stolen refresh token
        // can't survive a password change.
        UserSession::where('user_id', $user->id)->delete();

        $this->logAudit($user->id, 'password_reset', 'user', $user->id);

        return response()->json([
            'message' => 'Password reset successfully. Please log in again.',
        ]);
    }

    /**
     * Record a failed login attempt and lock the account once the threshold is hit.
     */
    protected function registerFailedLogin(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $update = ['failed_login_attempts' => $attempts];

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $update['locked_until'] = now()->addMinutes(self::LOCKOUT_MINUTES);
        }

        $user->update($update);

        $this->logAudit($user->id, 'login_failed', 'user', $user->id, null, [
            'failed_login_attempts' => $attempts,
        ]);
    }

    /**
     * Create and persist a single-use, hashed token row in user_tokens.
     * Returns the RAW (unhashed) token — this is the only point it's ever available.
     */
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

    /**
     * Create a DB-backed refresh-token session tied to the requesting device.
     * Returns ['session' => UserSession, 'refresh_token' => raw token string].
     */
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

    /**
     * Write a row to audit_logs. $userId may be null for system-initiated actions.
     */
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
