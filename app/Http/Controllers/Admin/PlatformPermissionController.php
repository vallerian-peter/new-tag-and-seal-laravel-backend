<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformPermissionController extends Controller
{
    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $users = User::query()->whereNotNull('uuid')->orderBy('id')->limit(200)->get(['id', 'uuid', 'username', 'role', 'platform_role', 'status']);
        $grants = DB::table('user_platform_permissions')->join('platform_permissions', 'platform_permissions.id', '=', 'user_platform_permissions.permission_id')
            ->select('user_platform_permissions.user_uuid', 'platform_permissions.code')->get()->groupBy('user_uuid');

        return response()->json(['data' => [
            'actor' => ['id' => $request->user()->id, 'platformRole' => $request->user()->platform_role],
            'users' => $users->map(fn ($user) => [
                'id' => $user->id, 'username' => $user->username, 'role' => $user->role, 'platformRole' => $user->platform_role, 'status' => $user->status,
                'permissions' => ($grants->get($user->uuid) ?? collect())->pluck('code')->values(),
            ]),
            'permissions' => DB::table('platform_permissions')->orderBy('name')->get(['code', 'name']),
        ]]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeManager($request);
        abort_if($request->user()->is($user), 403, 'You cannot change your own permissions.');
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', 'exists:platform_permissions,code'],
            'platformRole' => ['sometimes', Rule::in(['superAdmin', 'admin', 'appUser'])],
        ]);
        $isSuperAdmin = $request->user()->platform_role === 'superAdmin';
        if (! $isSuperAdmin && ($user->platform_role === 'superAdmin' || (isset($data['platformRole']) && $data['platformRole'] !== $user->platform_role) || in_array('permissions.manage', $data['permissions'], true))) {
            abort(403, 'Only a super administrator can change platform roles or permission management access.');
        }
        abort_if(empty($user->uuid), 409, 'This account needs a user UUID before permissions can be changed.');
        DB::transaction(function () use ($request, $user, $data): void {
            $before = DB::table('user_platform_permissions')->join('platform_permissions', 'platform_permissions.id', '=', 'user_platform_permissions.permission_id')
                ->where('user_platform_permissions.user_uuid', $user->uuid)->pluck('platform_permissions.code')->all();
            $ids = DB::table('platform_permissions')->whereIn('code', $data['permissions'])->pluck('id');
            DB::table('user_platform_permissions')->where('user_uuid', $user->uuid)->delete();
            foreach ($ids as $id) {
                DB::table('user_platform_permissions')->insert(['user_uuid' => $user->uuid, 'permission_id' => $id, 'granted_by_uuid' => $request->user()->uuid, 'created_at' => now(), 'updated_at' => now()]);
            }
            $beforeRole = $user->platform_role;
            if (isset($data['platformRole'])) {
                $user->forceFill(['platform_role' => $data['platformRole']])->save();
            }
            DB::table('audit_logs')->insert([
                'uuid' => (string) Str::uuid(), 'actor_uuid' => $request->user()->uuid, 'action' => 'permissions.updated',
                'entity_type' => 'user', 'entity_uuid' => $user->uuid, 'before_json' => json_encode(['permissions' => $before, 'platformRole' => $beforeRole]),
                'after_json' => json_encode(['permissions' => $data['permissions'], 'platformRole' => $user->platform_role]), 'request_uuid' => (string) Str::uuid(),
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Permissions updated.']);
    }
}
