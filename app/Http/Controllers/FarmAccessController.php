<?php

namespace App\Http\Controllers;

use App\Models\FarmUser;
use App\Models\User;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Hiring\FarmOwnership;
use App\Services\Hiring\GrantSources;
use App\Services\Hiring\HiringNotifications;
use App\Services\Hiring\WorkerPermissions;
use App\Services\PhoneAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FarmAccessController extends Controller
{
    private function requireOwner(Request $request, string $farmUuid): void
    {
        abort_unless(app(FarmOwnership::class)->owns($request->user(), $farmUuid), 403);
    }

    public function current(Request $request, string $farmUuid, FarmAccessService $access): JsonResponse
    {
        return response()->json(['data' => [
            'canView' => $access->canAccess($request->user(), $farmUuid),
            'canManageLivestock' => $access->can($request->user(), $farmUuid, 'livestock.manage'),
            'canCreateEvents' => $access->can($request->user(), $farmUuid, 'events.create') || collect(WorkerPermissions::MODULES)->contains(fn ($module) => $access->canModule($request->user(), $farmUuid, $module, 'create')),
            'modules' => collect(WorkerPermissions::MODULES)->mapWithKeys(fn ($module) => [$module => ['view' => $access->canModule($request->user(), $farmUuid, $module, 'view'), 'create' => $access->canModule($request->user(), $farmUuid, $module, 'create')]]),
            'canManageFinance' => $access->can($request->user(), $farmUuid, 'finance.manage'),
        ]]);
    }

    public function index(Request $request, string $farmUuid, FarmAccessService $access): JsonResponse
    {
        $this->requireOwner($request, $farmUuid);
        $profileIds = FarmUser::query()->where('status', 'active')->get()
            ->filter(fn ($profile) => in_array($farmUuid, $profile->getFarmUuidsArray(), true))->pluck('id');
        $legacyUsers = User::query()->where('role', 'farmInvitedUser')->whereIn('roleId', $profileIds)->pluck('id');
        $grantUsers = DB::table('farm_access_grants')->where('farm_uuid', $farmUuid)->pluck('user_id');
        $users = User::query()->whereIn('id', $legacyUsers->merge($grantUsers)->unique())->get();
        $data = $users->map(function (User $user) use ($access, $farmUuid): array {
            $permissions = [];
            if ($access->canAccess($user, $farmUuid)) {
                $permissions[] = 'farm.view';
            }
            foreach ([...WorkerPermissions::catalog(), 'events.create'] as $permission) {
                if ($access->can($user, $farmUuid, $permission)) {
                    $permissions[] = $permission;
                }
            }
            $profile = $user->role === 'farmInvitedUser' ? FarmUser::find($user->roleId) : null;
            $name = $profile ? trim($profile->firstName.' '.$profile->lastName) : null;

            return ['id' => $user->id, 'username' => $user->username, 'name' => $name ?: $user->username, 'permissions' => $this->directPermissions($user, $farmUuid), 'effectivePermissions' => array_values(array_unique($permissions))];
        });

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $farmUuid, User $user): JsonResponse
    {
        $this->requireOwner($request, $farmUuid);
        abort_if($request->user()->is($user), 403);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in([...WorkerPermissions::catalog(), 'events.create'])],
        ]);
        if ($data['permissions'] && ! in_array('farm.view', $data['permissions'], true)) {
            return response()->json(['message' => 'Farm access is required for other permissions.'], 422);
        }
        WorkerPermissions::validate(array_values(array_diff($data['permissions'], ['events.create'])));
        $this->writeGrant($request, $farmUuid, $user, $data['permissions']);

        return response()->json(['data' => ['userId' => $user->id, 'permissions' => $data['permissions']]]);
    }

    public function search(Request $request, string $farmUuid, PhoneAuthentication $phones, FarmAccessService $access): JsonResponse
    {
        $this->requireOwner($request, $farmUuid);
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $phone = $phones->normalize($data['phone']);
        if (! $phone) {
            return response()->json(['message' => 'Invalid phone number.'], 422);
        }
        $identity = DB::table('phone_identities')->where('phone', $phone)->first();
        $user = $identity ? User::find($identity->user_id) : null;
        if (! $user || ! $user->isActive() || $user->is($request->user())) {
            return response()->json(['message' => 'Member not found.'], 404);
        }
        $permissions = [];
        if ($access->canAccess($user, $farmUuid)) {
            $permissions[] = 'farm.view';
        }
        foreach ([...WorkerPermissions::catalog(), 'events.create'] as $permission) {
            if ($access->can($user, $farmUuid, $permission)) {
                $permissions[] = $permission;
            }
        }

        return response()->json(['data' => ['id' => $user->id, 'name' => $identity->display_name ?: $user->username, 'permissions' => $this->directPermissions($user, $farmUuid), 'effectivePermissions' => array_values(array_unique($permissions))]]);
    }

    public function revoke(Request $request, string $farmUuid, User $user): JsonResponse
    {
        $this->requireOwner($request, $farmUuid);
        abort_if($request->user()->is($user), 403);
        $this->writeGrant($request, $farmUuid, $user, []);

        return response()->json(['data' => ['userId' => $user->id, 'permissions' => []]]);
    }

    private function directPermissions(User $user, string $farmUuid): array
    {
        $raw = DB::table('farm_access_grant_sources')->where('farm_uuid', $farmUuid)->where('user_id', $user->id)->where('source_type', 'manual')->where('source_id', 'legacy')->value('permissions');

        return json_decode($raw ?? '[]', true) ?: [];
    }

    private function writeGrant(Request $request, string $farmUuid, User $user, array $permissions): void
    {
        DB::transaction(function () use ($request, $farmUuid, $user, $permissions): void {
            DB::table('farms')->where('uuid', $farmUuid)->lockForUpdate()->first();
            $before = DB::table('farm_access_grant_sources')->where('farm_uuid', $farmUuid)->where('user_id', $user->id)->where('source_type', 'manual')->where('source_id', 'legacy')->lockForUpdate()->value('permissions');
            if ($before !== null && json_decode($before, true) == $permissions) {
                return;
            }
            app(GrantSources::class)->write($farmUuid, $user->id, 'manual', 'legacy', $permissions, $request->user()->id);
            if ($before !== json_encode($permissions)) {
                app(HiringNotifications::class)->send('farm.permissions.'.(string) Str::uuid(), $user->id, null, 'permissions.updated', 'Your farm permissions have changed. Open Tag & Seal.', 'Ruhusa zako za shamba zimebadilishwa. Fungua Tag & Seal.', ['farmUuid' => $farmUuid]);
            }
            DB::table('audit_logs')->insert([
                'uuid' => (string) Str::uuid(), 'actor_uuid' => $request->user()->uuid, 'action' => 'farm.permissions.updated',
                'entity_type' => 'user', 'entity_uuid' => $user->uuid, 'before_json' => $before,
                'after_json' => json_encode(['farmUuid' => $farmUuid, 'permissions' => $permissions], JSON_THROW_ON_ERROR),
                'request_uuid' => (string) Str::uuid(), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'created_at' => now(),
            ]);
        });
    }
}
