<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\UserType;

/**
 * EnsureTenant Middleware
 * 
 * Ensures the authenticated user is a tenant.
 * Used to protect tenant-only routes.
 */
class EnsureTenant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        if ($request->user()->user_type !== UserType::TENANT) {
            return response()->json([
                'message' => 'This action is only available to tenants.'
            ], 403);
        }

        return $next($request);
    }
}
