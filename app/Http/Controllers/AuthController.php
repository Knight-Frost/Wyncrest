<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Events\UserCreated;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * AuthController
 *
 * Handles user and admin authentication.
 * SECURITY: Uses constant-time password verification and proper token handling.
 */
class AuthController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Register a new user (tenant or landlord).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'user_type' => ['required', 'string', 'in:tenant,landlord'],
        ]);

        $user = User::create([
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'] ?? null,
            'user_type' => UserType::from($validated['user_type']),
            'is_active' => true,
        ]);

        // Create Sanctum token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Audit log
        $this->auditService->logUserCreated($user);

        // Fire UserCreated event → triggers SendWelcomeEmail listener
        event(new UserCreated($user));

        return response()->json([
            'user' => $this->formatUser($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Authenticate user and return token.
     *
     * SECURITY: Implements brute-force protection with rate limiting.
     * - 5 attempts per minute per IP+email combination
     * - Lockout duration: 60 seconds
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Rate limiting key: IP + email hash
        $throttleKey = $this->throttleKey($request, $validated['email']);

        // Check if too many attempts
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            // Audit log suspicious activity
            $this->auditService->log(
                actor: null,
                action: 'login_rate_limited',
                subject: null,
                description: "Login rate limit exceeded for email: {$validated['email']}",
                metadata: ['ip' => $request->ip(), 'email' => $validated['email']],
                severity: 'warning'
            );

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        // NOTE: This endpoint authenticates tenants/landlords only (Sanctum bearer
        // tokens). Admins authenticate through the isolated cookie-session surface
        // at POST /api/admin/login (AdminAuthController). An admin email entered
        // here intentionally falls through to the User lookup below and returns the
        // same "no account found" response as any unknown email — no enumeration,
        // and no admin bearer token is ever issued from here.
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            // No account with this email
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['No account found with this email address.'],
            ]);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            // Email found but password is wrong
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'password' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user is active
        if (! $user->is_active) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        // Check if user is suspended
        if ($user->suspended_at !== null) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['Your account has been suspended.'],
            ]);
        }

        // Clear rate limiter on successful login
        RateLimiter::clear($throttleKey);

        // Create Sanctum token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Audit log
        $this->auditService->log(
            actor: $user,
            action: 'user_login',
            subject: $user,
            description: "User logged in: {$user->email}",
            severity: 'info'
        );

        return response()->json([
            'user' => $this->formatUser($user),
            'token' => $token,
        ]);
    }

    /**
     * Generate a throttle key for login rate limiting.
     */
    protected function throttleKey(Request $request, string $email): string
    {
        return Str::transliterate(Str::lower($email).'|'.$request->ip());
    }

    /**
     * Get current authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Admin) {
            return response()->json([
                'user' => $this->formatAdmin($user),
            ]);
        }

        return response()->json([
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Logout (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Change the authenticated principal's password.
     *
     * Works for both User (tenant/landlord) and Admin principals — they share
     * this endpoint via $request->user().
     *
     * SECURITY:
     * - Requires and constant-time verifies the current password.
     * - Enforces the same strong-password policy as registration.
     * - Rejects reusing the current password.
     * - Revokes every OTHER access token (signs out other devices) while
     *   keeping the current session alive.
     * - Writes a critical audit log for both principal types (the universal
     *   security record) and, for Users, an in-app security notification.
     *   Admins have no in-app notification channel (separate table), so the
     *   audit log is their record — we do not fabricate a notification.
     */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User|Admin $principal */
        $principal = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Verify the current password (constant-time).
        if (! Hash::check($validated['current_password'], $principal->password)) {
            // A wrong current-password is a security-relevant signal — audit it.
            $this->auditService->log(
                actor: $principal,
                action: 'password_change_failed',
                subject: $principal,
                description: "Password change failed (incorrect current password): {$principal->email}",
                severity: 'warning'
            );

            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Disallow no-op changes so "other sessions" aren't revoked for nothing
        // and so a compromised-but-known password can't be re-set to itself.
        if (Hash::check($validated['password'], $principal->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from your current password.'],
            ]);
        }

        $principal->password = Hash::make($validated['password']);
        $principal->save();

        // Revoke all OTHER tokens; keep the current session authenticated.
        $currentToken = $principal->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->getKey() : null;

        $tokenQuery = $principal->tokens();
        if ($currentTokenId !== null) {
            $tokenQuery->where('id', '!=', $currentTokenId);
        }
        $revokedOtherSessions = $tokenQuery->delete();

        // Critical audit log — the universal security record for both types.
        $this->auditService->log(
            actor: $principal,
            action: 'password_changed',
            subject: $principal,
            description: "Password changed: {$principal->email}",
            metadata: ['revoked_other_sessions' => $revokedOtherSessions],
            severity: 'critical'
        );

        // In-app security notification (Users only — admins have no channel).
        if ($principal instanceof User) {
            $this->notificationService->create(
                user: $principal,
                type: NotificationType::PASSWORD_CHANGED,
                title: 'Password Changed',
                message: "Your account password was just changed. If this wasn't you, contact support immediately.",
                data: [
                    'event_id' => "password-changed:{$principal->id}:".now()->timestamp,
                    'revoked_other_sessions' => $revokedOtherSessions,
                ]
            );
        }

        return response()->json([
            'message' => 'Password updated successfully. Other sessions have been signed out.',
            'revoked_other_sessions' => $revokedOtherSessions,
        ]);
    }

    /**
     * Format user data for response.
     */
    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'user_type' => $user->user_type->value,
            'is_active' => $user->is_active,
            'identity_verified' => $user->identity_verified,
            'verification_status' => $user->verification_status instanceof \App\Enums\VerificationStatus
                ? $user->verification_status->value
                : ($user->verification_status ?? 'unverified'),
            'account_status' => $user->account_status instanceof \App\Enums\AccountStatus
                ? $user->account_status->value
                : ($user->account_status ?? 'active'),
            'created_at' => $user->created_at->toISOString(),
        ];
    }

    /**
     * Format admin data for response.
     *
     * Only reachable if an Admin presents a bearer token to the shared /user
     * endpoint (admins normally use the cookie-session /api/admin/me). Delegates
     * to the single source of truth so the shape can never drift.
     */
    protected function formatAdmin(Admin $admin): array
    {
        return $admin->toAuthPayload();
    }
}
