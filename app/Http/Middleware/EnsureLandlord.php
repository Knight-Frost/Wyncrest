<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Enums\UserType;

/**
 * EnsureLandlord Middleware
 * 
 * Ensures the authenticated user is a landlord.
 * Used to protect landlord-only routes.
 */
class EnsureLandlord
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

        if ($request->user()->user_type !== UserType::LANDLORD) {
            return response()->json([
                'message' => 'This action is only available to landlords.'
            ], 403);
        }

        return $next($request);
    }
}
