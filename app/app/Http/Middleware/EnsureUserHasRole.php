<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Restrict access to users whose `role` column matches one of the
     * given values. Roles come from the `users.role` ENUM: 'customer',
     * 'staff', 'admin'.
     *
     * Usage in routes:
     *   Route::middleware(['auth:api', 'role:admin'])->group(...);
     *   Route::middleware(['auth:api', 'role:admin,staff'])->group(...);
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
