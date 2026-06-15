<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAdminOrLandlord Middleware
 *
 * Ensures the authenticated user is an admin OR a landlord.
 * Used for routes that should be accessible to platform administrators
 * and property owners (e.g., metrics, analytics).
 */
class EnsureAdminOrLandlord
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if authenticated via admin guard
        if (Auth::guard('admin')->check()) {
            return $next($request);
        }

        // Check Sanctum-authenticated user
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Check if the authenticated user is from the Admin model
        if ($user instanceof \App\Models\Admin) {
            // Check if admin is active
            if (! $user->is_active) {
                return response()->json([
                    'message' => 'Your admin account has been deactivated.',
                ], 403);
            }

            return $next($request);
        }

        // Check if user is a landlord
        if ($user instanceof \App\Models\User && $user->user_type === UserType::LANDLORD) {
            // Security: Check if account is active
            if (! $user->is_active) {
                return response()->json([
                    'message' => 'Your account has been deactivated.',
                ], 403);
            }

            // Security: Check if account is suspended
            if ($user->suspended_at !== null) {
                return response()->json([
                    'message' => 'Your account has been suspended.',
                ], 403);
            }

            return $next($request);
        }

        return response()->json([
            'message' => 'This action is only available to administrators and landlords.',
        ], 403);
    }
}
