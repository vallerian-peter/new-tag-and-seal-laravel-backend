<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            'configuration.view' => 'View dynamic configuration',
            'configuration.manage' => 'Create and edit draft configuration',
            'configuration.publish' => 'Publish and retire configuration',
            'subjects.view' => 'View animal subjects',
            'subjects.manage' => 'Register and update animal subjects',
            'events.submit' => 'Submit animal events',
            'events.void' => 'Void animal events',
            'records.archive' => 'Archive operational records',
            'records.restore' => 'Restore archived operational records',
            'upgrades.view' => 'View platform upgrade status',
            'upgrades.run' => 'Run platform upgrades',
            'permissions.manage' => 'Manage platform permissions',
        ];

        foreach ($permissions as $code => $name) {
            DB::table('platform_permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $permissionIds = DB::table('platform_permissions')->pluck('id');
        $administrators = DB::table('users')
            ->where('role', 'systemUser')
            ->whereNotNull('uuid')
            ->pluck('uuid');

        foreach ($administrators as $userUuid) {
            foreach ($permissionIds as $permissionId) {
                DB::table('user_platform_permissions')->updateOrInsert(
                    ['user_uuid' => $userUuid, 'permission_id' => $permissionId],
                    ['granted_by_uuid' => null, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('user_platform_permissions')->delete();
        DB::table('platform_permissions')->whereIn('code', [
            'configuration.view', 'configuration.manage', 'configuration.publish',
            'subjects.view', 'subjects.manage', 'events.submit', 'events.void',
            'records.archive', 'records.restore', 'upgrades.view', 'upgrades.run',
            'permissions.manage',
        ])->delete();
    }
};
