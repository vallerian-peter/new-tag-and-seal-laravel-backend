<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user->platform_role === 'superAdmin') {
            return $next($request);
        }
        if ($request->is('api/v1/admin/dairy/cooperatives/approvals') ||
            $request->is('api/v1/admin/dairy/cooperatives/approvals/*/review') ||
            ($request->isMethod('GET') && $request->is('api/v1/admin/dairy/cooperatives/amcos')) ||
            $request->is('api/v1/admin/dairy/transport/*') ||
            ($request->isMethod('POST') && $request->is('api/v1/admin/dairy/factories/*/handovers/*/decision'))) {
            return $next($request);
        }

        $area = $request->segment(4);
        $read = $request->isMethod('GET') || $request->isMethod('HEAD');
        $permission = match ($area) {
            'dashboard', 'analytics' => 'reports.view',
            'users', 'people', 'otp-verifications' => $read ? 'accounts.view' : 'accounts.manage',
            'platform-permissions' => 'permissions.manage',
            'locations', 'reference', 'blueprints' => $read ? 'configuration.view' : ($request->segment(7) === 'publish' ? 'configuration.publish' : 'configuration.manage'),
            'animals' => $read ? 'subjects.view' : ($request->segment(7) === 'restore' ? 'records.restore' : ($request->isMethod('DELETE') ? 'records.archive' : 'subjects.manage')),
            'events' => $read ? 'events.view' : ($request->segment(7) === 'restore' ? 'records.restore' : ($request->isMethod('DELETE') ? 'events.void' : 'events.submit')),
            'finance' => $read ? 'finance.view' : 'finance.manage',
            'commerce' => $read ? 'commerce.view' : 'commerce.manage',
            'career' => $read ? 'accounts.view' : 'accounts.manage',
            'dairy', 'logistics' => $read ? 'dairy.view' : 'dairy.manage',
            default => null,
        };
        abort_unless($permission !== null && $user->hasPlatformPermission($permission), 403);

        return $next($request);
    }
}
