<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureLandlord Middleware
 *
 * Ensures the authenticated user is a landlord.
 * Used to protect landlord-only routes.
 *
 * Security: Also validates account is active and not suspended.
 */
class EnsureLandlord
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->user_type !== UserType::LANDLORD) {
            return response()->json([
                'message' => 'This action is only available to landlords.',
            ], 403);
        }

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
}
