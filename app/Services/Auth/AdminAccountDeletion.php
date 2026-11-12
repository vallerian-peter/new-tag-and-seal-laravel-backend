<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAccountDeletion
{
    public function check(User $user): array
    {
        $admins = User::whereIn('platform_role', ['admin', 'superAdmin'])->where('status', 'active');
        if ($user->platform_role === 'superAdmin' && (clone $admins)->where('platform_role', 'superAdmin')->count() <= 1) {
            return $this->blocked('lastSuperAdmin', 'Assign another active super administrator before deleting your account.');
        }
        if ($admins->count() <= 1) {
            return $this->blocked('lastAdmin', 'Another active administrator is required before you can delete your account.');
        }
        if ($user->role !== 'systemUser' && $user->roleId) {
            return $this->blocked('linkedProfile', 'Your account has a linked operational profile. Contact another administrator to review it before deleting your account.');
        }

        // Preserve operational history, including tables that would otherwise cascade
        // on account deletion. Nullable audit references may be cleared by the database.
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, ['phone_identities', 'user_platform_permissions', 'sessions'], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($name) as $key) {
                if ($key['foreign_table'] !== 'users' || strtolower($key['on_delete']) === 'set null') {
                    continue;
                }
                $query = DB::table($name);
                foreach ($key['columns'] as $index => $column) {
                    $query->where($column, $user->getRawOriginal($key['foreign_columns'][$index]));
                }
                if ($query->exists()) {
                    return $this->blocked('accountInUse', 'Your account is linked to operational records. Ask another administrator to review those records before deleting your account.');
                }
            }
        }

        return ['canDelete' => true];
    }

    private function blocked(string $code, string $message): array
    {
        return ['canDelete' => false, 'code' => $code, 'message' => $message];
    }
}
