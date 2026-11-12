<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformPermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_permission_managers_can_change_other_accounts_and_changes_are_audited(): void
    {
        $manager = User::factory()->create();
        $target = User::factory()->create();
        $permissionId = DB::table('platform_permissions')->where('code', 'permissions.manage')->value('id');
        $this->actingAs($manager)->getJson('/api/v1/admin/platform-permissions')->assertForbidden();
        DB::table('user_platform_permissions')->insert([
            'user_uuid' => $manager->uuid, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($manager)->getJson('/api/v1/admin/platform-permissions')->assertOk();
        $this->actingAs($manager)->putJson('/api/v1/admin/platform-permissions/'.$manager->id, ['permissions' => []])->assertForbidden();
        $this->actingAs($manager)->putJson('/api/v1/admin/platform-permissions/'.$target->id, ['permissions' => ['subjects.view']])->assertOk();
        $this->assertTrue($target->hasPlatformPermission('subjects.view'));
        $this->assertDatabaseHas('audit_logs', ['actor_uuid' => $manager->uuid, 'entity_uuid' => $target->uuid, 'action' => 'permissions.updated']);
        $this->actingAs($manager)->putJson('/api/v1/admin/platform-permissions/'.$target->id, ['permissions' => []])->assertOk();
        $this->assertFalse($target->hasPlatformPermission('subjects.view'));
    }

    public function test_only_super_admin_can_assign_platform_roles(): void
    {
        $manager = User::factory()->create();
        $target = User::factory()->create();
        $permissionId = DB::table('platform_permissions')->where('code', 'permissions.manage')->value('id');
        DB::table('user_platform_permissions')->insert([
            'user_uuid' => $manager->uuid, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($manager)->putJson('/api/v1/admin/platform-permissions/'.$target->id, [
            'permissions' => [], 'platformRole' => 'superAdmin',
        ])->assertForbidden();
        $manager->forceFill(['platform_role' => 'superAdmin'])->save();
        $this->actingAs($manager)->putJson('/api/v1/admin/platform-permissions/'.$target->id, [
            'permissions' => ['subjects.view'], 'platformRole' => 'appUser',
        ])->assertOk();
        $this->assertSame('appUser', $target->fresh()->platform_role);
        $this->actingAs($target->fresh())->getJson('/api/v1/admin/platform-permissions')->assertForbidden();
    }

    public function test_admin_routes_enforce_read_and_write_permissions(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertForbidden();
        $viewPermission = DB::table('platform_permissions')->where('code', 'accounts.view')->value('id');
        DB::table('user_platform_permissions')->insert([
            'user_uuid' => $admin->uuid, 'permission_id' => $viewPermission, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/admin/users', [])->assertForbidden();
        $admin->forceFill(['platform_role' => 'superAdmin'])->save();
        $this->actingAs($admin)->postJson('/api/v1/admin/users', [])->assertUnprocessable();
    }
}
