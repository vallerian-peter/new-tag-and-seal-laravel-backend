<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        if (! $request->user()->isActive()) {
            return response()->json(['status' => false, 'message' => 'Account is inactive.'], 403);
        }

        $platformAdmin = in_array('systemUser', $roles, true)
            && in_array($request->user()->platform_role, ['admin', 'superAdmin'], true);
        $legacyAllowed = in_array($request->user()->role, $roles, true)
            && ($request->user()->role !== 'systemUser' || $platformAdmin);
        if (! $legacyAllowed && ! $platformAdmin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. You do not have permission to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}

