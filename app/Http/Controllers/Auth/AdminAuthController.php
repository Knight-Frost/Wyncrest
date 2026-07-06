<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * AdminAuthController — cookie/session authentication for the admin console.
 *
 * SECURITY MODEL (deliberately separate from tenant/landlord auth):
 *   - Admins authenticate with a first-party, server-side session carried by an
 *     HttpOnly, Secure (in production), SameSite session cookie. No bearer token
 *     is ever issued to, or stored by, the admin SPA — so JavaScript cannot read
 *     the credential and client state can never silently diverge from the real
 *     authenticated admin (the bug this architecture exists to prevent).
 *   - The session authenticates the native `admin` guard (config/auth.php), which
 *     is scoped to the Admin model / admins table. It is intentionally NOT part of
 *     `sanctum.guard`, so an admin session can never leak into the shared
 *     `auth:sanctum` (tenant/landlord bearer) pipeline.
 *   - The backend is the SOLE source of truth: GET /api/admin/me returns the
 *     authenticated admin from the session. The SPA treats that as authoritative.
 *
 * These endpoints are protected by the `web` middleware group (session + CSRF)
 * and, for the authenticated ones, `auth:admin` + EnsureAdmin/EnsureAdminCan.
 */
class AdminAuthController extends Controller
{
    public function __construct(protected AuditService $auditService) {}

    /**
     * Authenticate an admin and establish a session.
     *
     * Requires a valid CSRF token (the SPA calls GET /sanctum/csrf-cookie first).
     * On success the session is regenerated (fixation defense) and the admin
     * identity is returned — never a token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $throttleKey = $this->throttleKey($request, $validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            $this->auditService->log(
                actor: null,
                action: 'admin_login_rate_limited',
                subject: null,
                description: "Admin login rate limit exceeded for email: {$validated['email']}",
                metadata: ['ip' => $request->ip(), 'email' => $validated['email']],
                severity: 'warning'
            );

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        $admin = Admin::where('email', $validated['email'])->first();

        // Uniform failure for unknown email OR wrong password (no user enumeration).
        if (! $admin || ! Hash::check($validated['password'], $admin->password)) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $admin->is_active) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['Your admin account has been deactivated.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Establish the first-party session on the admin guard and rotate the
        // session id to defeat fixation. `remember` issues the long-lived
        // remember-me cookie (also HttpOnly/Secure) when requested.
        Auth::guard('admin')->login($admin, (bool) ($validated['remember'] ?? false));
        $request->session()->regenerate();

        $admin->recordLogin();
        $this->auditService->logAdminLogin($admin);

        return response()->json(['user' => $admin->toAuthPayload()]);
    }

    /**
     * Return the currently authenticated admin (source of truth for the SPA).
     *
     * Guaranteed non-null by the `auth:admin` middleware on the route; if the
     * session is missing/expired the middleware returns 401 before we get here.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        return response()->json(['user' => $admin->toAuthPayload()]);
    }

    /**
     * Destroy the admin session (server-side logout).
     *
     * Invalidates the session and rotates the CSRF token so the cookie left in
     * the browser is inert.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $this->auditService->log(
            actor: $admin,
            action: 'admin_logout',
            subject: $admin,
            description: "Admin logged out: {$admin->email}",
            severity: 'info'
        );

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Change the authenticated admin's password.
     *
     * SECURITY:
     * - Constant-time verifies the current password.
     * - Enforces the registration-strength policy and rejects no-op changes.
     * - Invalidates the admin's OTHER sessions (logoutOtherDevices) so a changed
     *   password immediately ends any other logged-in admin session, while
     *   keeping the current one alive.
     * - Writes a critical audit record (admins have no in-app notification channel,
     *   so the audit log is the security record — we do not fabricate one).
     */
    public function password(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        if (! Hash::check($validated['current_password'], $admin->password)) {
            $this->auditService->log(
                actor: $admin,
                action: 'password_change_failed',
                subject: $admin,
                description: "Admin password change failed (incorrect current password): {$admin->email}",
                severity: 'warning'
            );

            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($validated['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from your current password.'],
            ]);
        }

        $admin->password = Hash::make($validated['password']);
        $admin->save();

        // End every OTHER session for this admin, keeping the current one. Must
        // run AFTER the save: logoutOtherDevices() confirms the given password is
        // now the current one, then rotates the session password-hash marker that
        // the `auth.session` middleware checks — so other sessions are logged out
        // on their next request.
        Auth::guard('admin')->logoutOtherDevices($validated['password']);

        $this->auditService->log(
            actor: $admin,
            action: 'password_changed',
            subject: $admin,
            description: "Admin changed their password: {$admin->email}",
            severity: 'critical'
        );

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * Update the authenticated admin's own display name and email.
     *
     * Self-service identity edit — available to every admin (super or scoped),
     * not gated by any capability, since it only ever touches the caller's own
     * record. Email is unique across `admins`; the DB constraint backs the
     * validation rule below.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('admins', 'email')->ignore($admin->id),
            ],
        ]);

        $email = strtolower(trim($validated['email']));
        $oldValues = ['name' => $admin->name, 'email' => $admin->email];

        $admin->name = trim($validated['name']);
        $admin->email = $email;
        $admin->save();

        $newValues = ['name' => $admin->name, 'email' => $admin->email];

        if ($newValues !== $oldValues) {
            $this->auditService->log(
                actor: $admin,
                action: 'admin_profile_updated',
                subject: $admin,
                description: "Admin updated their profile: {$admin->email}",
                oldValues: $oldValues,
                newValues: $newValues,
                severity: 'info'
            );
        }

        return response()->json(['user' => $admin->toAuthPayload()]);
    }

    /**
     * Rate-limit key: lowercased email + client IP (matches the tenant/landlord flow).
     */
    protected function throttleKey(Request $request, string $email): string
    {
        return 'admin|'.Str::transliterate(Str::lower($email).'|'.$request->ip());
    }
}
