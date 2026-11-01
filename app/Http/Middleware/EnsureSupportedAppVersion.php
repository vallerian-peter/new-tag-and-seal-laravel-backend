<?php

namespace App\Http\Middleware;

use App\Services\Ledger\AppVersionPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupportedAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $minimum = AppVersionPolicy::updateRequiredFor($request->header(AppVersionPolicy::HEADER));
        if ($minimum !== null) {
            return response()->json([
                'status' => false,
                'code' => 'APP_UPDATE_REQUIRED',
                'minimumAppVersion' => $minimum,
                'message' => "Please update Tag & Seal to version $minimum or later to sign in.",
            ], 426);
        }

        return $next($request);
    }
}
