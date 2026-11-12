<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'accounts.view' => 'View user accounts and people',
        'accounts.manage' => 'Manage user accounts and people',
        'reports.view' => 'View dashboard and analytics',
        'events.view' => 'View events',
        'finance.view' => 'View finance records',
        'finance.manage' => 'Manage finance records',
        'commerce.view' => 'View commerce records',
        'commerce.manage' => 'Manage commerce records',
        'dairy.view' => 'View milk and logistics records',
        'dairy.manage' => 'Manage milk and logistics records',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $code => $name) {
            DB::table('platform_permissions')->updateOrInsert(['code' => $code], ['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Legacy system users previously had broad admin access. Preserve it
        // during migration; permission management remains reserved separately.
        $ids = DB::table('platform_permissions')->where('code', '!=', 'permissions.manage')->pluck('id');
        $users = DB::table('users')->where('role', 'systemUser')->where('platform_role', 'admin')->whereNotNull('uuid')->pluck('uuid');
        foreach ($users as $uuid) {
            foreach ($ids as $id) {
                DB::table('user_platform_permissions')->updateOrInsert(
                    ['user_uuid' => $uuid, 'permission_id' => $id],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('platform_permissions')->whereIn('code', array_keys($this->permissions))->pluck('id');
        DB::table('user_platform_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('platform_permissions')->whereIn('id', $ids)->delete();
    }
};
