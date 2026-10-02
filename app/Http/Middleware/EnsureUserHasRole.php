<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $roleName = $request->user()?->role?->role_name;

        if (! in_array($roleName, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this resource.',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
