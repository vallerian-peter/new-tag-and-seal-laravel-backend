<?php

namespace App\Services\Dynamic;

use App\Enums\UserRole;
use App\Models\EventDefinition;
use App\Models\EventRecord;
use App\Models\Farm;
use App\Models\FarmUser;
use App\Models\User;
use App\Services\Hiring\FarmOwnership;
use Illuminate\Support\Facades\DB;

class FarmAccessService
{
    private function ownsFarm(User $user, string $farmUuid): bool
    {
        return app(FarmOwnership::class)->owns($user, $farmUuid);
    }

    public function canAccess(User $user, string $farmUuid): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($user->platform_role === 'superAdmin' || ($user->platform_role === 'admin' && $user->hasPlatformPermission('subjects.view'))) {
            return true;
        }
        if ($this->ownsFarm($user, $farmUuid)) {
            return true;
        }

        $explicit = $this->explicitPermissions($user, $farmUuid);
        if ($explicit !== null) {
            return in_array('farm.view', $explicit, true);
        }

        if (in_array($user->role, [UserRole::FARM_INVITED_USER, UserRole::VET], true)) {
            $farmUser = FarmUser::find($user->roleId);

            return $farmUser && $farmUser->status === 'active' && in_array($farmUuid, $farmUser->getFarmUuidsArray(), true);
        }

        return false;
    }

    /** Null means there is no explicit grant; an empty list explicitly denies access. */
    private function explicitPermissions(User $user, string $farmUuid): ?array
    {
        $raw = DB::table('farm_access_grants')->where('user_id', $user->id)->where('farm_uuid', $farmUuid)->value('permissions');

        return $raw === null ? null : (json_decode($raw, true) ?: []);
    }

    public function can(User $user, string $farmUuid, string $permission): bool
    {
        if (! $this->canAccess($user, $farmUuid)) {
            return false;
        }
        if ($user->platform_role === 'superAdmin' || $this->ownsFarm($user, $farmUuid)) {
            return true;
        }
        if ($user->platform_role === 'admin' && $user->hasPlatformPermission(match ($permission) {
            'livestock.manage' => 'subjects.manage', 'events.create' => 'events.submit', default => $permission
        })) {
            return true;
        }
        $explicit = $this->explicitPermissions($user, $farmUuid);
        if ($explicit !== null) {
            return in_array($permission, $explicit, true);
        }
        $profile = $user->role === UserRole::FARM_INVITED_USER ? FarmUser::find($user->roleId) : null;
        if (! $profile || $profile->status !== 'active') {
            return false;
        }
        if ($profile->roleTitle === 'farm-manager') {
            return true;
        }
        $legacyEventRoles = ['feeding-user', 'weight-change-user', 'deworming-user', 'medication-user', 'vaccination-user', 'disposal-user', 'birth-event-user', 'aborted-pregnancy-user', 'dryoff-user', 'insemination-user', 'pregnancy-user', 'milking-user', 'transfer-user'];

        return $permission === 'events.create' && in_array($profile->roleTitle, $legacyEventRoles, true);
    }

    public function canModule(User $user, string $farm, ?string $module, string $action): bool
    {
        if (! $this->canAccess($user, $farm)) {
            return false;
        }
        if ($this->ownsFarm($user, $farm) || $user->platform_role === 'superAdmin') {
            return true;
        }
        if ($module === 'finance') {
            return $this->can($user, $farm, 'finance.manage');
        }
        if ($action === 'create' && $this->can($user, $farm, 'events.create')) {
            return true;
        }
        $explicit = $this->explicitPermissions($user, $farm);
        if ($explicit === null) {
            return $action === 'view' || $this->can($user, $farm, 'events.create');
        }
        if (in_array('events.create', $explicit, true)) {
            return true;
        } // preserved legacy broad grant

        return $module !== null && in_array("work.$module.$action", $explicit, true);
    }

    public function canEvent(User $user, string $farm, EventDefinition $definition, string $action): bool
    {
        return $this->canModule($user, $farm, $definition->access_module_code, $action);
    }

    public function canReadRecord(User $user, EventRecord $record): bool
    {
        $definition = EventDefinition::where('uuid', $record->event_definition_uuid)->first();

        return $this->canModule($user, $record->farm_uuid, $definition?->access_module_code, 'view');
    }

    /** @return array<int, string>|null farm UUIDs the user may read; null means every farm. */
    public function accessibleFarmUuids(User $user): ?array
    {
        if (! $user->isActive()) {
            return [];
        }
        if ($user->platform_role === 'superAdmin' || ($user->platform_role === 'admin' && $user->hasPlatformPermission('subjects.view'))) {
            return null;
        }
        $owned = Farm::query()->whereIn('farmerId', app(FarmOwnership::class)->farmerIds($user))->pluck('uuid')->all();
        $profile = in_array($user->role, [UserRole::FARM_INVITED_USER, UserRole::VET], true) ? FarmUser::find($user->roleId) : null;
        $legacy = $profile && $profile->status === 'active' ? $profile->getFarmUuidsArray() : [];
        $explicit = DB::table('farm_access_grants')->where('user_id', $user->id)->get(['farm_uuid', 'permissions']);
        foreach ($explicit as $grant) {
            $legacy = array_values(array_diff($legacy, [$grant->farm_uuid]));
            if (in_array('farm.view', json_decode($grant->permissions, true) ?: [], true)) {
                $legacy[] = $grant->farm_uuid;
            }
        }

        return array_values(array_unique([...$owned, ...$legacy]));
    }
}
