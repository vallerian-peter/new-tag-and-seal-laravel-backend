<?php

namespace App\Services\Dynamic;

use App\Enums\UserRole;
use App\Models\Farm;
use App\Models\FarmUser;
use App\Models\User;

class FarmAccessService
{
    public function canAccess(User $user, string $farmUuid): bool
    {
        if ($user->role === UserRole::SYSTEM_USER) {
            return true;
        }
        if ($user->role === UserRole::FARMER) {
            return Farm::query()->where('uuid', $farmUuid)->where('farmerId', $user->roleId)->exists();
        }

        if (in_array($user->role, [UserRole::FARM_INVITED_USER, UserRole::VET], true)) {
            $farmUser = FarmUser::find($user->roleId);

            return $farmUser && in_array($farmUuid, $farmUser->getFarmUuidsArray(), true);
        }

        return false;
    }

    /** @return array<int, string>|null farm UUIDs the user may read; null means every farm. */
    public function accessibleFarmUuids(User $user): ?array
    {
        if ($user->role === UserRole::SYSTEM_USER) {
            return null;
        }
        if ($user->role === UserRole::FARMER) {
            return Farm::query()->where('farmerId', $user->roleId)->pluck('uuid')->all();
        }
        if (in_array($user->role, [UserRole::FARM_INVITED_USER, UserRole::VET], true)) {
            return FarmUser::find($user->roleId)?->getFarmUuidsArray() ?? [];
        }

        return [];
    }
}
