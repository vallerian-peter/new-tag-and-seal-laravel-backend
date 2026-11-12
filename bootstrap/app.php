<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureSupportedAppVersion;
use App\Services\Ledger\LegacyRetiredException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'check.role' => CheckRole::class,
            'app.version' => EnsureSupportedAppVersion::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (LegacyRetiredException $error) => response()->json([
            'status' => false, 'code' => 'LEGACY_RETIRED', 'table' => $error->table, 'family' => $error->family,
            'message' => $error->getMessage(),
        ], 410));

        $exceptions->render(function (\Illuminate\Database\QueryException $error, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                \Illuminate\Support\Facades\Log::error('API Database QueryException: '.$error->getMessage());

                return response()->json([
                    'status' => false,
                    'message' => 'A database query error occurred while processing your request. Please ensure all migrations have been applied.',
                    'code' => 'DATABASE_QUERY_ERROR',
                ], 500);
            }
        });

        $exceptions->render(function (\PDOException $error, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                \Illuminate\Support\Facades\Log::error('API Database PDOException: '.$error->getMessage());

                return response()->json([
                    'status' => false,
                    'message' => 'Unable to connect to the database service. Please ensure the MySQL server is running.',
                    'code' => 'DATABASE_CONNECTION_ERROR',
                ], 503);
            }
        });
    })->create();
