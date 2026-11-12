<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/v1/admin/account/profile';

    private function values(array $changes = []): array
    {
        return [...['firstName' => 'Asha', 'lastName' => 'Juma', 'email' => 'asha@example.test', 'phone' => '+255712345678'], ...$changes];
    }

    public function test_administrator_can_edit_only_their_profile_without_management_permissions(): void
    {
        $user = User::factory()->create(['platform_role' => 'admin']);
        $other = User::factory()->create(['platform_role' => 'superAdmin']);
        $this->actingAs($user)->getJson($this->url)->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.platformRole', 'admin')->assertJsonPath('data.permissions', []);
        $this->patchJson($this->url, $this->values(['id' => $other->id]))->assertOk()
            ->assertJsonPath('data.firstName', 'Asha')->assertJsonPath('data.lastName', 'Juma')
            ->assertJsonPath('data.email', 'asha@example.test')->assertJsonPath('data.phone', '+255712345678');
        $this->assertDatabaseHas('system_users', ['id' => $user->roleId, 'firstName' => 'Asha', 'lastName' => 'Juma']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'platform_role' => 'admin', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'email' => $other->email]);
        $this->getJson('/api/auth/profile')->assertOk()->assertJsonPath('data.profile.firstName', 'Asha');
    }

    public function test_profile_changes_validate_identity_and_cannot_escalate_access(): void
    {
        $user = User::factory()->create(['platform_role' => 'admin']);
        $other = User::factory()->create();
        $this->actingAs($user);
        foreach (['platformRole' => 'superAdmin', 'platform_role' => 'superAdmin', 'role' => 'farmer', 'status' => 'notActive', 'roleId' => $other->roleId, 'permissions' => ['permissions.manage']] as $field => $value) {
            $this->patchJson($this->url, $this->values([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson($this->url, $this->values(['email' => $other->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson($this->url, $this->values(['email' => strtoupper($other->email)]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson($this->url, $this->values(['firstName' => ' ', 'phone' => 'not-a-phone']))->assertUnprocessable()->assertJsonValidationErrors('firstName');
        $this->patchJson($this->url, $this->values(['phone' => '123']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'platform_role' => 'admin']);
    }

    public function test_verified_sign_in_phone_cannot_be_silently_reassigned(): void
    {
        $user = User::factory()->create();
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255712345678', 'pin_hash' => 'hash']);
        $this->actingAs($user)->getJson($this->url)->assertOk()->assertJsonPath('data.phoneReadOnly', true);
        $this->patchJson($this->url, $this->values(['phone' => '+255712345679']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson($this->url, $this->values())->assertOk();
        $this->assertDatabaseHas('phone_identities', ['user_id' => $user->id, 'phone' => '+255712345678', 'display_name' => 'Asha Juma']);
    }

    public function test_self_service_requires_an_active_platform_administrator(): void
    {
        $this->getJson($this->url)->assertUnauthorized();
        $user = User::factory()->create(['platform_role' => 'appUser']);
        $this->actingAs($user)->getJson($this->url)->assertForbidden();
        $this->patchJson($this->url, $this->values())->assertForbidden();
        $this->deleteJson($this->url, ['confirmation' => 'delete'])->assertForbidden();
        $user->forceFill(['platform_role' => 'admin', 'status' => 'notActive'])->save();
        $this->getJson($this->url)->assertForbidden();
    }

    public function test_platform_admin_with_phone_identity_can_edit_without_changing_operational_role(): void
    {
        $user = User::factory()->create(['role' => 'buyer', 'roleId' => 0, 'platform_role' => 'admin']);
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255712345678', 'pin_hash' => 'hash', 'display_name' => 'Old Name']);
        $this->actingAs($user)->getJson($this->url)->assertOk()->assertJsonPath('data.firstName', 'Old')->assertJsonPath('data.lastName', 'Name');
        $this->patchJson($this->url, $this->values())->assertOk()->assertJsonPath('data.firstName', 'Asha')->assertJsonPath('data.lastName', 'Juma');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'buyer', 'roleId' => 0, 'platform_role' => 'admin']);
    }

    public function test_last_super_administrator_is_protected_and_deletion_rechecks_eligibility(): void
    {
        $user = User::factory()->create(['platform_role' => 'superAdmin']);
        $this->actingAs($user)->getJson('/api/v1/admin/account/deletion-check')->assertOk()->assertJsonPath('data.canDelete', false)->assertJsonPath('data.code', 'lastSuperAdmin');
        $this->deleteJson($this->url, ['confirmation' => 'delete'])->assertConflict();
        $other = User::factory()->create(['platform_role' => 'superAdmin']);
        $this->getJson('/api/v1/admin/account/deletion-check')->assertOk()->assertJsonPath('data.canDelete', true);
        $other->update(['status' => 'notActive']);
        $this->deleteJson($this->url, ['confirmation' => 'delete'])->assertConflict()->assertJsonPath('code', 'lastSuperAdmin');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_officer_admin_updates_the_correct_profile_and_keeps_email_linkage(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Region', 'shortName' => 'RG', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'District', 'regionId' => $region]);
        $ward = DB::table('wards')->insertGetId(['name' => 'Ward', 'districtId' => $district]);
        $id = DB::table('system_users')->insertGetId(['firstName' => 'Other', 'lastName' => 'Person', 'phone' => '0711000000', 'status' => 'active']);
        DB::table('extension_officers')->insert([
            'id' => $id, 'firstName' => 'Officer', 'lastName' => 'One', 'email' => 'officer@example.test',
            'phone' => '0722000000', 'password' => 'unused', 'gender' => 'male', 'countryId' => $country,
            'regionId' => $region, 'districtId' => $district, 'wardId' => $ward,
        ]);
        $user = User::factory()->create(['role' => 'extensionOfficer', 'roleId' => $id, 'platform_role' => 'admin', 'email' => 'officer@example.test']);
        $this->actingAs($user)->patchJson($this->url, $this->values())->assertOk()->assertJsonPath('data.firstName', 'Asha');
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.firstName', 'Asha')->assertJsonPath('data.email', 'asha@example.test');
        $this->assertDatabaseHas('extension_officers', ['id' => $id, 'firstName' => 'Asha', 'email' => 'asha@example.test']);
        $this->assertDatabaseHas('system_users', ['id' => $id, 'firstName' => 'Other']);
    }

    public function test_last_administrator_and_linked_operational_records_are_protected(): void
    {
        $user = User::factory()->create(['platform_role' => 'admin']);
        $this->actingAs($user)->getJson('/api/v1/admin/account/deletion-check')->assertOk()->assertJsonPath('data.code', 'lastAdmin');
        User::factory()->create(['platform_role' => 'superAdmin']);
        Schema::create('account_test_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
        DB::table('account_test_records')->insert(['user_id' => $user->id]);
        $this->getJson('/api/v1/admin/account/deletion-check')->assertOk()->assertJsonPath('data.canDelete', false)->assertJsonPath('data.code', 'accountInUse');
        $this->deleteJson($this->url, ['confirmation' => 'delete'])->assertConflict();
        $this->assertDatabaseHas('account_test_records', ['user_id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_confirmed_deletion_removes_only_the_current_login_and_revokes_all_tokens(): void
    {
        $user = User::factory()->create(['platform_role' => 'admin']);
        $other = User::factory()->create(['platform_role' => 'superAdmin']);
        $user->createToken('browser');
        $user->createToken('phone');
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255712345678', 'pin_hash' => 'hash']);
        $this->actingAs($user)->deleteJson($this->url)->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->deleteJson($this->url, ['confirmation' => 'wrong'])->assertUnprocessable();
        $this->deleteJson($this->url, ['confirmation' => 'delete', 'id' => $other->id])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('phone_identities', ['user_id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        // Linked profile history is retained; only the sign-in account is removed.
        $this->assertDatabaseHas('system_users', ['id' => $user->roleId]);
    }
}
