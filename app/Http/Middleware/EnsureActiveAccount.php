<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isActive(), 403, 'Account is inactive.');

        return $next($request);
    }
}
