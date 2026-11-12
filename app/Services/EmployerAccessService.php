<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class EmployerAccessService
{
    public function can(User $actor, int $ownerId, string $permission): bool
    {
        if (! $actor->isActive()) return false;
        $owner = User::find($ownerId);
        if (! $owner || ! $owner->isActive() || ! DB::table('feature_memberships')->where('user_id', $ownerId)->where('feature', 'jobOwner')->exists()) return false;
        if ((int) $actor->id === $ownerId) return true;
        $raw = DB::table('employer_access_grants')->where('owner_user_id', $ownerId)->where('member_user_id', $actor->id)->value('permissions');
        $permissions = $raw ? json_decode($raw, true) : [];
        return is_array($permissions) && in_array($permission, $permissions, true);
    }
}
