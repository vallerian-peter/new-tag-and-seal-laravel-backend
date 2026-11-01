<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminBoundaryTest extends TestCase
{
    public function test_admin_routes_are_isolated_and_protected(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin'));

        $this->assertNotEmpty($adminRoutes);

        $adminRoutes->each(function ($route): void {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('api', $middleware, $route->uri().' must use the API middleware group.');
            $this->assertContains('auth:sanctum', $middleware, $route->uri().' must require authentication.');
            $this->assertContains('check.role:systemUser', $middleware, $route->uri().' must be restricted to system users.');
        });
    }
}
