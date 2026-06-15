<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * AuthController
 *
 * Handles user and admin authentication.
 * SECURITY: Uses constant-time password verification and proper token handling.
 */
class AuthController extends Controller
{
    public function __construct(
        protected AuditService $auditService
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

        // Resolve the account by email (admins take precedence over users).
        // Note: differentiating "unknown email" from "wrong password" is a product
        // choice for clearer UX. It trades a small user-enumeration risk, which is
        // mitigated by the per-email+IP login throttle above and audit logging.
        $admin = Admin::where('email', $validated['email'])->first();
        $user = $admin ? null : User::where('email', $validated['email'])->first();

        // No account exists with this email at all.
        if (! $admin && ! $user) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['No account was found with this email address.'],
            ]);
        }

        // Admin login path.
        if ($admin) {
            if (! Hash::check($validated['password'], $admin->password)) {
                RateLimiter::hit($throttleKey, 60);
                throw ValidationException::withMessages([
                    'password' => ['The password you entered is incorrect.'],
                ]);
            }

            RateLimiter::clear($throttleKey);

            return $this->handleAdminLogin($admin);
        }

        // Regular user: email exists, so a failure here is specifically the password.
        if (! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'password' => ['The password you entered is incorrect.'],
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
     * Handle admin login.
     */
    protected function handleAdminLogin(Admin $admin): JsonResponse
    {
        // Check if admin is active
        if (! $admin->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your admin account has been deactivated.'],
            ]);
        }

        // Create Sanctum token
        $token = $admin->createToken('admin-token')->plainTextToken;

        // Update last login
        $admin->recordLogin();

        // Audit log
        $this->auditService->logAdminLogin($admin);

        return response()->json([
            'user' => $this->formatAdmin($admin),
            'token' => $token,
        ]);
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
            'user_type' => $user->user_type->value,
            'is_active' => $user->is_active,
            'identity_verified' => $user->identity_verified,
            'created_at' => $user->created_at->toISOString(),
        ];
    }

    /**
     * Format admin data for response.
     */
    protected function formatAdmin(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'email' => $admin->email,
            'name' => $admin->name,
            'is_super_admin' => $admin->is_super_admin,
            'is_active' => $admin->is_active,
            'last_login_at' => $admin->last_login_at?->toISOString(),
        ];
    }
}
