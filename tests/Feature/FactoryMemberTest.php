<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FactoryMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('identity_card_types')->insert(['name' => 'National ID']);
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Mbeya', 'shortName' => 'MB', 'countryId' => $country]);
        DB::table('districts')->insertGetId(['name' => 'Rungwe', 'regionId' => $region]);
        $admin = User::factory()->create();
        $admin->forceFill(['platform_role' => 'superAdmin'])->save();
        Sanctum::actingAs($admin, ['*']);
    }

    private function companyData(string $uuid): array
    {
        $mcu = DB::table('cooperative_mcus')->value('uuid');
        if (! $mcu) {
            $mcu = (string) Str::uuid();
            DB::table('cooperative_mcus')->insert(['uuid' => $mcu, 'name' => 'Test MCU', 'mcu_code' => 'MCU-TEST', 'is_active' => true]);
        }

        return ['uuid' => $uuid, 'name' => 'Mbeya Dairy Ltd', 'registration_number' => 'BRELA-001', 'tin_number' => '123456789',
            'mcu_uuid' => $mcu, 'region_id' => DB::table('regions')->value('id'), 'district_id' => DB::table('districts')->value('id'), 'contact_phone' => '0754111222'];
    }

    public function test_company_registration_has_no_staff_until_members_are_explicitly_added(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/factories', $this->companyData($uuid))->assertCreated()
            ->assertJsonPath('data.tin_number', '123-456-789')->assertJsonPath('data.contact_name', null);
        $this->assertSame(0, DB::table('factory_operators')->count());
        $this->postJson("/api/v1/admin/dairy/factories/{$uuid}/members", [
            'first_name' => 'Neema', 'last_name' => 'Mushi', 'identity_card_type_id' => DB::table('identity_card_types')->value('id'), 'identity_number' => 'ID-001', 'phone' => '0754111222', 'role' => 'manager',
        ])->assertCreated();

        $members = $this->getJson("/api/v1/admin/dairy/factories/{$uuid}/members")->assertOk()->json('data');
        $this->assertCount(1, $members);
        $this->assertSame('manager', $members[0]['role']);
        $this->assertSame('Neema Mushi', $members[0]['name']);
        $this->assertSame('+255754111222', $members[0]['phone']);
        $this->getJson("/api/v1/admin/dairy/cooperatives/factories/{$uuid}")->assertOk()
            ->assertJsonPath('data.uuid', $uuid)->assertJsonPath('members.0.role', 'manager')
            ->assertJsonPath('meta.canManage', true);
        $this->getJson('/api/v1/admin/dairy/cooperatives/factories/missing')->assertNotFound();

        $operator = $this->postJson("/api/v1/admin/dairy/factories/{$uuid}/members", [
            'first_name' => 'Baraka', 'last_name' => 'Juma', 'identity_card_type_id' => DB::table('identity_card_types')->value('id'), 'identity_number' => 'ID-002', 'phone' => '0765333444', 'role' => 'operator', 'staff_id' => 'QA-7',
        ])->assertCreated()->json('data');
        $this->assertSame('operator', $operator['role']);
        $this->assertSame('ID-002', $operator['identity_number']);
        $this->assertSame('Baraka', $operator['first_name']);

        $this->postJson("/api/v1/admin/dairy/factories/{$uuid}/members", [
            'first_name' => 'Baraka', 'last_name' => 'Juma', 'identity_card_type_id' => DB::table('identity_card_types')->value('id'), 'identity_number' => 'ID-009', 'phone' => '+255 765 333 444', 'role' => 'manager',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame(2, DB::table('factory_operators')->where('factory_uuid', $uuid)->count());
        $this->assertSame(1, DB::table('phone_identities')->where('phone', '+255765333444')->count());

        $this->patchJson("/api/v1/admin/dairy/factories/{$uuid}/members/{$operator['uuid']}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $userId = DB::table('factory_operators')->where('uuid', $operator['uuid'])->value('user_id');
        Sanctum::actingAs(User::find($userId));
        $this->getJson('/api/v2/dairy/factory/trips')->assertForbidden();
    }

    public function test_factory_detail_keeps_member_data_restricted(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/factories', $this->companyData($uuid))->assertCreated();
        $reader = User::factory()->create(['role' => 'systemUser', 'platform_role' => 'admin']);
        Sanctum::actingAs($reader, ['*']);
        $this->getJson("/api/v1/admin/dairy/cooperatives/factories/{$uuid}")->assertForbidden();
        DB::table('user_platform_permissions')->insert([
            'user_uuid' => $reader->uuid,
            'permission_id' => DB::table('platform_permissions')->where('code', 'dairy.view')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson("/api/v1/admin/dairy/cooperatives/factories/{$uuid}")->assertOk()
            ->assertJsonPath('data.uuid', $uuid)->assertJsonPath('members', null)->assertJsonPath('meta.canManage', false);
    }

    public function test_factory_company_validation_and_updates_do_not_change_members(): void
    {
        $uuid = (string) Str::uuid();
        $data = $this->companyData($uuid);
        $url = '/api/v1/admin/dairy/cooperatives/factories';
        $this->postJson($url, [...$data, 'tin_number' => '123'])->assertUnprocessable()->assertJsonValidationErrors('tin_number');
        $this->postJson($url, [...$data, 'mcu_uuid' => null])->assertUnprocessable()->assertJsonValidationErrors('mcu_uuid');
        $this->postJson($url, [...$data, 'amcos_uuid' => 'old-amcos'])->assertUnprocessable()->assertJsonValidationErrors('amcos_uuid');
        $this->postJson($url, [...$data, 'operator_type' => 'individual'])->assertUnprocessable()->assertJsonValidationErrors('operator_type');
        $this->postJson($url, [...$data, 'contact_name' => 'Personal Manager'])->assertUnprocessable()->assertJsonValidationErrors('contact_name');
        $this->postJson($url, $data)->assertCreated();
        $this->patchJson($url.'/'.$uuid, ['tin_number' => '987654321', 'contact_email' => 'office@example.com'])->assertOk()
            ->assertJsonPath('data.tin_number', '987-654-321')->assertJsonPath('data.contact_email', 'office@example.com');
        $this->patchJson($url.'/'.$uuid, ['mcu_uuid' => 'missing'])->assertUnprocessable();
        $this->patchJson($url.'/'.$uuid, ['is_active' => false])->assertOk();
        $this->assertSame(0, DB::table('factory_operators')->count());
        $this->assertSame(0, DB::table('phone_identities')->count());
        $this->getJson($url.'?search=987-654')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_member_registration_requires_identity_and_role_and_validates_optional_tin(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/factories', $this->companyData($uuid))->assertCreated();
        $url = "/api/v1/admin/dairy/factories/{$uuid}/members";
        $this->getJson($url)->assertOk()->assertJsonPath('identity_card_types.0.name', 'National ID');
        $this->postJson($url, ['phone' => '0754111222'])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'identity_card_type_id', 'identity_number', 'role']);
        $data = ['first_name' => 'Asha', 'middle_name' => 'Neema', 'last_name' => 'Juma', 'phone' => '0754111222',
            'identity_card_type_id' => DB::table('identity_card_types')->value('id'), 'identity_number' => 'ID-3', 'role' => 'operator'];
        $this->postJson($url, [...$data, 'role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson($url, [...$data, 'identity_card_type_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('identity_card_type_id');
        $this->postJson($url, [...$data, 'tin_number' => '123'])->assertUnprocessable()->assertJsonValidationErrors('tin_number');
        $this->postJson($url, [...$data, 'phone' => '12ab'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $asha = $this->postJson($url, $data)->assertCreated()->assertJsonPath('data.tin_number', null)->assertJsonPath('data.name', 'Asha Neema Juma')->json('data');
        $this->postJson($url, [...$data, 'tin_number' => '123456789'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson($url, [...$data, 'phone' => '0754999000'])->assertUnprocessable()->assertJsonValidationErrors('identity_number');
        $this->patchJson("{$url}/{$asha['uuid']}", ['tin_number' => '123456789'])->assertOk()->assertJsonPath('data.tin_number', '123-456-789');
        $this->assertDatabaseHas('factory_operators', ['factory_uuid' => $uuid, 'first_name' => 'Asha', 'identity_number' => 'ID-3', 'role' => 'operator']);
        $this->assertSame(1, DB::table('factory_operators')->count());
    }

    public function test_factory_location_follows_mcu_coverage(): void
    {
        $uuid = (string) Str::uuid();
        $data = $this->companyData($uuid);
        $district = $data['district_id'];
        $ward = DB::table('wards')->insertGetId(['name' => 'Factory ward', 'districtId' => $district]);
        DB::table('cooperative_mcus')->where('uuid', $data['mcu_uuid'])->update([
            'all_regions' => false, 'region_id' => $data['region_id'], 'district_id' => $district, 'ward_id' => $ward,
        ]);
        $this->getJson('/api/v1/admin/dairy/cooperatives/options')->assertOk()->assertJsonMissingPath('data.0.ward_id');
        unset($data['region_id']);
        // The factory selects its own district and ward, ignoring legacy MCU office fields.
        $district = DB::table('districts')->insertGetId(['name' => 'Factory district', 'regionId' => DB::table('regions')->value('id')]);
        $ward = DB::table('wards')->insertGetId(['name' => 'Factory ward', 'districtId' => $district]);
        $data['district_id'] = $district;
        $data['ward_id'] = $ward;
        $url = '/api/v1/admin/dairy/cooperatives/factories';
        $this->postJson($url, $data)->assertCreated()->assertJsonPath('data.district_id', $district)->assertJsonPath('data.ward_id', $ward);
        $this->getJson($url.'/'.$uuid)->assertOk()->assertJsonPath('data.ward_name', 'Factory ward');
        DB::table('cooperative_mcus')->where('uuid', $data['mcu_uuid'])->update(['all_regions' => true, 'region_id' => null, 'district_id' => null, 'ward_id' => null]);
        $secondRegion = DB::table('regions')->insertGetId(['name' => 'Other region', 'shortName' => 'OR', 'countryId' => DB::table('countries')->value('id')]);
        $secondDistrict = DB::table('districts')->insertGetId(['name' => 'Other district', 'regionId' => $secondRegion]);
        $secondWard = DB::table('wards')->insertGetId(['name' => 'Other ward', 'districtId' => $secondDistrict]);
        $this->patchJson($url.'/'.$uuid, ['region_id' => $secondRegion, 'district_id' => $secondDistrict, 'ward_id' => $ward])->assertUnprocessable()->assertJsonValidationErrors('ward_id');
        $this->patchJson($url.'/'.$uuid, ['region_id' => $secondRegion, 'district_id' => $secondDistrict, 'ward_id' => $secondWard])->assertOk()->assertJsonPath('data.ward_id', $secondWard);
    }

    public function test_members_can_be_edited_and_deleted_but_the_last_manager_stays(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/factories', $this->companyData($uuid))->assertCreated();
        $member = fn (string $first, string $phone, string $role) => $this->postJson("/api/v1/admin/dairy/factories/{$uuid}/members", [
            'first_name' => $first, 'last_name' => 'Mushi', 'identity_card_type_id' => DB::table('identity_card_types')->value('id'),
            'identity_number' => 'ID-'.$first, 'phone' => $phone, 'role' => $role,
        ])->assertCreated()->json('data');
        $manager = $member('Neema', '0754111222', 'manager');
        $operator = $member('Baraka', '0765333444', 'operator');
        $url = fn (array $m) => "/api/v1/admin/dairy/factories/{$uuid}/members/{$m['uuid']}";

        $this->patchJson($url($operator), ['first_name' => 'Barnaba', 'tin_number' => '123456789'])->assertOk()
            ->assertJsonPath('data.name', 'Barnaba Mushi')->assertJsonPath('data.tin_number', '123-456-789');
        $this->patchJson($url($manager), ['role' => 'operator'])->assertUnprocessable();

        $this->patchJson($url($operator), ['phone' => '0754111222'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson($url($operator), ['phone' => 'not a phone'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson($url($operator), ['identity_number' => 'ID-Neema'])->assertUnprocessable()->assertJsonValidationErrors('identity_number');
        $this->patchJson($url($operator), ['phone' => '0711 222 333'])->assertOk()->assertJsonPath('data.phone', '+255711222333');
        $this->assertDatabaseHas('phone_identities', ['user_id' => $operator['user_id'], 'phone' => '+255711222333']);
        $this->assertDatabaseMissing('phone_identities', ['phone' => '+255765333444']);
        $this->patchJson($url($operator), ['phone' => '+255711222333'])->assertOk();
        $this->deleteJson($url($manager))->assertUnprocessable();

        $this->deleteJson($url($operator))->assertOk();
        $this->assertDatabaseMissing('factory_operators', ['uuid' => $operator['uuid']]);
        $this->assertDatabaseHas('users', ['id' => $operator['user_id']]);
        $this->deleteJson($url($operator))->assertNotFound();
    }

    public function test_only_super_admin_manages_members(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson('/api/v1/admin/dairy/factories/missing/members')->assertForbidden();
    }
}
