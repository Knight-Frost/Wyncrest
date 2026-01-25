<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAdmin Middleware
 *
 * Ensures the authenticated user is an admin.
 * Used to protect admin-only routes.
 * Works with the separate Admin model and Sanctum authentication.
 *
 * IMPORTANT: This middleware must run AFTER auth:sanctum middleware.
 */
class EnsureAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the authenticated user from the request
        // After auth:sanctum middleware runs, $request->user() returns the authenticated user
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Check if the authenticated user is from the Admin model
        if (!($user instanceof \App\Models\Admin)) {
            return response()->json([
                'message' => 'This action is only available to administrators.'
            ], 403);
        }

        // Check if admin is active
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your admin account has been deactivated.'
            ], 403);
        }

        return $next($request);
    }
}
