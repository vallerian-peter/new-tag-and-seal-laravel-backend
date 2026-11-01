<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\District;
use App\Models\ExtensionOfficer;
use App\Models\ExtensionOfficerFarmInvite;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\FarmUser;
use App\Models\IdentityCardType;
use App\Models\LegalStatus;
use App\Models\Region;
use App\Models\SchoolLevel;
use App\Models\Street;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Village;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeopleAndUsersCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SystemUser $adminProfile;

    private Country $country;

    private Region $region;

    private District $district;

    private Ward $ward;

    private Village $village;

    private Street $street;

    private LegalStatus $legalStatus;

    private IdentityCardType $idCardType;

    private SchoolLevel $schoolLevel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->country = Country::create(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $this->region = Region::create(['name' => 'Arusha', 'shortName' => 'AR', 'countryId' => $this->country->id]);
        $this->district = District::create(['name' => 'Arusha Urban', 'shortName' => 'AU', 'regionId' => $this->region->id]);
        $this->ward = Ward::create(['name' => 'Sekei', 'shortName' => 'SK', 'districtId' => $this->district->id]);
        $this->village = Village::create(['name' => 'Sekei Village', 'shortName' => 'SV', 'wardId' => $this->ward->id]);
        $this->street = Street::create(['name' => 'Sanawari Street', 'wardId' => $this->ward->id]);
        $this->legalStatus = LegalStatus::create(['name' => 'Sole Proprietorship']);
        $this->idCardType = IdentityCardType::create(['name' => 'NIDA']);
        $this->schoolLevel = SchoolLevel::create(['name' => 'University']);

        $this->adminProfile = SystemUser::create([
            'firstName' => 'System',
            'lastName' => 'Admin',
            'phone' => '+255700100100',
            'address' => 'Arusha HQ',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'username' => 'sys.admin',
            'email' => 'sys.admin@tagandseal.co.tz',
            'password' => 'password123',
            'role' => UserRole::SYSTEM_USER,
            'roleId' => $this->adminProfile->id,
            'status' => UserStatus::ACTIVE,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_non_admin_cannot_access_people_or_users_admin_endpoints(): void
    {
        $farmer = Farmer::create([
            'farmerNo' => 'FRM-NONADMIN',
            'firstName' => 'John',
            'surname' => 'Farmer',
            'phone1' => '+255711000111',
            'gender' => 'male',
            'countryId' => $this->country->id,
            'farmerType' => 'individual',
            'status' => 'active',
        ]);

        $farmerUser = User::create([
            'username' => 'john.farmer',
            'email' => 'john.farmer@example.com',
            'password' => 'password123',
            'role' => UserRole::FARMER,
            'roleId' => $farmer->id,
            'status' => UserStatus::ACTIVE,
        ]);

        Sanctum::actingAs($farmerUser);

        foreach (['farms', 'farmers', 'extension-officers', 'farm-users', 'farm-invites', 'system-user-profiles'] as $resource) {
            $this->getJson("/api/v1/admin/people/{$resource}")->assertForbidden();
        }
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_farmers_crud_search_filter_sort_and_delete_protection(): void
    {
        $this->getJson('/api/v1/admin/people/farmers/options')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'data' => ['countries', 'regions', 'districts', 'wards', 'villages', 'streets', 'identityCardTypes', 'schoolLevels', 'creators'],
            ]);

        $createResponse = $this->postJson('/api/v1/admin/people/farmers', [
            'firstName' => 'Amina',
            'middleName' => 'Juma',
            'surname' => 'Mwakasege',
            'phone1' => '+255754111222',
            'phone2' => '+255754111333',
            'email' => 'amina@farms.co.tz',
            'physicalAddress' => 'Plot 12 Sekei',
            'farmerOrganizationMembership' => 'TDCU',
            'dateOfBirth' => '1988-05-14',
            'gender' => 'female',
            'identityCardTypeId' => $this->idCardType->id,
            'identityNumber' => '19880514-12345-00001-12',
            'streetId' => $this->street->id,
            'schoolLevelId' => $this->schoolLevel->id,
            'villageId' => $this->village->id,
            'wardId' => $this->ward->id,
            'districtId' => $this->district->id,
            'regionId' => $this->region->id,
            'countryId' => $this->country->id,
            'farmerType' => 'individual',
            'status' => 'active',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.fullName', 'Amina Juma Mwakasege')
            ->assertJsonPath('data.countryName', 'Tanzania')
            ->assertJsonPath('data.regionName', 'Arusha')
            ->assertJsonPath('data.districtName', 'Arusha Urban')
            ->assertJsonPath('data.wardName', 'Sekei')
            ->assertJsonPath('data.villageName', 'Sekei Village')
            ->assertJsonPath('data.streetName', 'Sanawari Street')
            ->assertJsonPath('data.identityCardTypeName', 'NIDA')
            ->assertJsonPath('data.schoolLevelName', 'University')
            ->assertJsonPath('data.createdByName', 'sys.admin');

        $generatedFarmerNo = (string) $createResponse->json('data.farmerNo');
        $this->assertMatchesRegularExpression('/^FMR-\d{8}-\d{4}-\d{3}$/', $generatedFarmerNo);

        $farmerId = (int) $createResponse->json('data.id');

        $linkedUser = User::query()
            ->where('role', UserRole::FARMER)
            ->where('roleId', $farmerId)
            ->first();

        $this->assertNotNull($linkedUser);
        $this->assertSame('amina@farms.co.tz', $linkedUser->email);

        $this->getJson("/api/v1/admin/people/farmers/{$farmerId}")
            ->assertOk()
            ->assertJsonPath('data.id', $farmerId)
            ->assertJsonPath('data.farmerNo', $generatedFarmerNo);

        $this->patchJson("/api/v1/admin/people/farmers/{$farmerId}", [
            'farmerNo' => 'FMR-TAMPERED-000',
            'firstName' => 'Amina Updated',
            'farmerType' => 'organization',
            'status' => 'notActive',
            'password' => 'newSecretPass99',
        ])
            ->assertOk()
            ->assertJsonPath('data.farmerNo', $generatedFarmerNo)
            ->assertJsonPath('data.firstName', 'Amina Updated')
            ->assertJsonPath('data.farmerType', 'organization')
            ->assertJsonPath('data.status', 'notActive');

        $this->assertTrue(Hash::check('newSecretPass99', $linkedUser->fresh()->password));
        $this->assertSame(UserStatus::NOT_ACTIVE, $linkedUser->fresh()->status);

        $this->getJson('/api/v1/admin/people/farmers?search=Mwakasege&status=notActive&sort=alpha-asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $farmerId);

        // Soft delete moves farmer to the Deleted tab
        $this->deleteJson("/api/v1/admin/people/farmers/{$farmerId}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->getJson('/api/v1/admin/people/farmers?tab=all')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.deletedCount', 1);

        $this->getJson('/api/v1/admin/people/farmers?tab=deleted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $farmerId);

        // Restore brings farmer back to the All tab
        $this->patchJson("/api/v1/admin/people/farmers/{$farmerId}?restore=1", ['action' => 'restore'])
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->getJson('/api/v1/admin/people/farmers?tab=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.deletedCount', 0);

        // Permanent delete removes farmer and linked user account
        $this->deleteJson("/api/v1/admin/people/farmers/{$farmerId}?force=1")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('farmers', ['id' => $farmerId]);
        $this->assertDatabaseMissing('users', ['id' => $linkedUser->id]);
    }

    public function test_farms_crud_search_filter_sort_and_delete_protection(): void
    {
        $farmer = Farmer::create([
            'farmerNo' => 'FN-2001',
            'firstName' => 'Baraka',
            'surname' => 'Mollel',
            'phone1' => '+255755200200',
            'gender' => 'male',
            'countryId' => $this->country->id,
            'farmerType' => 'individual',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/admin/people/farms/options')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['farmers', 'countries', 'regions', 'districts', 'wards', 'villages', 'legalStatuses'],
            ]);

        $createResponse = $this->postJson('/api/v1/admin/people/farms', [
            'farmerId' => $farmer->id,
            'referenceNo' => 'FRM-REF-001',
            'regionalRegNo' => 'AR-REG-99',
            'name' => 'Kilimanjaro Dairy Ranch',
            'size' => 125.5,
            'sizeUnit' => 'hectare',
            'latitudes' => -3.3869,
            'longitudes' => 36.6830,
            'physicalAddress' => 'Usa River Road',
            'villageId' => $this->village->id,
            'wardId' => $this->ward->id,
            'districtId' => $this->district->id,
            'regionId' => $this->region->id,
            'countryId' => $this->country->id,
            'legalStatusId' => $this->legalStatus->id,
            'status' => 'active',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'Kilimanjaro Dairy Ranch')
            ->assertJsonPath('data.farmerName', 'Baraka Mollel')
            ->assertJsonPath('data.countryName', 'Tanzania')
            ->assertJsonPath('data.regionName', 'Arusha')
            ->assertJsonPath('data.districtName', 'Arusha Urban')
            ->assertJsonPath('data.wardName', 'Sekei')
            ->assertJsonPath('data.villageName', 'Sekei Village')
            ->assertJsonPath('data.legalStatusName', 'Sole Proprietorship');

        $farmId = (int) $createResponse->json('data.id');

        $this->getJson("/api/v1/admin/people/farms/{$farmId}")
            ->assertOk()
            ->assertJsonPath('data.referenceNo', 'FRM-REF-001');

        $this->patchJson("/api/v1/admin/people/farms/{$farmId}", [
            'name' => 'Kilimanjaro Prime Ranch',
            'status' => 'not-active',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kilimanjaro Prime Ranch')
            ->assertJsonPath('data.status', 'not-active');

        $this->getJson('/api/v1/admin/people/farms?search=Prime&status=not-active&sort=alpha-desc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $farmId);

        // Soft delete moves farm to the Deleted tab
        $this->deleteJson("/api/v1/admin/people/farms/{$farmId}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->getJson('/api/v1/admin/people/farms?tab=all')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.deletedCount', 1);

        $this->getJson('/api/v1/admin/people/farms?tab=deleted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $farmId);

        // Restore brings farm back to All tab
        $this->postJson("/api/v1/admin/people/farms/{$farmId}/restore")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->getJson('/api/v1/admin/people/farms?tab=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.deletedCount', 0);

        // Permanent delete removes farm
        $this->deleteJson("/api/v1/admin/people/farms/{$farmId}?force=1")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('farms', ['id' => $farmId]);
    }

    public function test_extension_officers_crud_and_linked_user_cleanup(): void
    {
        $this->getJson('/api/v1/admin/people/extension-officers/options')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['countries', 'regions', 'districts', 'wards', 'villages'],
            ]);

        $createResponse = $this->postJson('/api/v1/admin/people/extension-officers', [
            'firstName' => 'Grace',
            'middleName' => 'Neema',
            'lastName' => 'Kimaro',
            'email' => 'grace.kimaro@vet.go.tz',
            'phone' => '+255766111222',
            'password' => 'officerPass123',
            'gender' => 'female',
            'licenseNumber' => 'VET-LIC-402',
            'organization' => 'Ministry of Livestock',
            'specialization' => 'Veterinary & AI',
            'countryId' => $this->country->id,
            'regionId' => $this->region->id,
            'districtId' => $this->district->id,
            'wardId' => $this->ward->id,
            'address' => 'Sekei Extension Office',
            'isVerified' => true,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.fullName', 'Grace Neema Kimaro')
            ->assertJsonPath('data.isVerified', true);

        $officerId = (int) $createResponse->json('data.id');

        $linkedOfficerAccount = User::query()
            ->where('role', UserRole::EXTENSION_OFFICER)
            ->where('roleId', $officerId)
            ->first();

        $this->assertNotNull($linkedOfficerAccount);
        $this->assertSame('grace.kimaro@vet.go.tz', $linkedOfficerAccount->email);
        $this->assertTrue(Hash::check('officerPass123', $linkedOfficerAccount->password));

        $this->patchJson("/api/v1/admin/people/extension-officers/{$officerId}", [
            'organization' => 'National Livestock Institute',
            'isVerified' => false,
            'password' => 'updatedOfficerPass456',
        ])
            ->assertOk()
            ->assertJsonPath('data.organization', 'National Livestock Institute')
            ->assertJsonPath('data.isVerified', false);

        $this->assertTrue(Hash::check('updatedOfficerPass456', $linkedOfficerAccount->fresh()->password));
        $this->assertSame(UserStatus::NOT_ACTIVE, $linkedOfficerAccount->fresh()->status);

        $this->getJson('/api/v1/admin/people/extension-officers?search=Kimaro&status=unverified&sort=desc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $officerId);

        $this->deleteJson("/api/v1/admin/people/extension-officers/{$officerId}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('extension_officers', ['id' => $officerId]);
        $this->assertDatabaseMissing('users', ['id' => $linkedOfficerAccount->id]);
    }

    public function test_farm_users_crud_auto_creates_syncs_and_deletes_linked_user_account(): void
    {
        $farmer = Farmer::create([
            'farmerNo' => 'FN-3001',
            'firstName' => 'Daudi',
            'surname' => 'Massawe',
            'phone1' => '+255755300300',
            'gender' => 'male',
            'countryId' => $this->country->id,
            'farmerType' => 'individual',
            'status' => 'active',
        ]);

        $farm = Farm::create([
            'farmerId' => $farmer->id,
            'uuid' => 'farm-uuid-3001',
            'referenceNo' => 'FRM-3001',
            'name' => 'Meru Highlands Farm',
            'size' => 45,
            'sizeUnit' => 'acre',
            'physicalAddress' => 'Tengeru',
            'countryId' => $this->country->id,
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/admin/people/farm-users/options')
            ->assertOk()
            ->assertJsonPath('data.farms.0.value', 'farm-uuid-3001');

        $createResponse = $this->postJson('/api/v1/admin/people/farm-users', [
            'farmUuid' => $farm->uuid,
            'roleTitle' => 'farm-manager',
            'firstName' => 'kelvin',
            'middleName' => 'peter',
            'lastName' => 'mushi',
            'phone' => '+255788100200',
            'email' => 'kelvin.mushi@merufarm.co.tz',
            'gender' => 'male',
            'status' => 'active',
            'password' => 'farmManagerPass1',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.farmName', 'Meru Highlands Farm')
            ->assertJsonPath('data.roleTitle', 'farm-manager');

        $farmUserId = (int) $createResponse->json('data.id');

        $linkedUser = User::query()
            ->where('role', UserRole::FARM_INVITED_USER)
            ->where('roleId', $farmUserId)
            ->first();

        $this->assertNotNull($linkedUser);
        $this->assertSame('kelvin.mushi@merufarm.co.tz', $linkedUser->email);
        $this->assertTrue(Hash::check('farmManagerPass1', $linkedUser->password));

        $this->patchJson("/api/v1/admin/people/farm-users/{$farmUserId}", [
            'roleTitle' => 'milking-user',
            'email' => 'kelvin.updated@merufarm.co.tz',
            'status' => 'notActive',
            'password' => 'newFarmManagerPass2',
        ])
            ->assertOk()
            ->assertJsonPath('data.roleTitle', 'milking-user')
            ->assertJsonPath('data.status', 'notActive');

        $linkedUser->refresh();
        $this->assertSame('kelvin.updated@merufarm.co.tz', $linkedUser->email);
        $this->assertSame(UserStatus::NOT_ACTIVE, $linkedUser->status);
        $this->assertTrue(Hash::check('newFarmManagerPass2', $linkedUser->password));

        $this->deleteJson("/api/v1/admin/people/farm-users/{$farmUserId}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('farm_users', ['id' => $farmUserId]);
        $this->assertDatabaseMissing('users', ['id' => $linkedUser->id]);
    }

    public function test_farm_invites_crud_auto_generates_access_code_and_resolves_farm(): void
    {
        $farmer = Farmer::create([
            'farmerNo' => 'FN-4001',
            'firstName' => 'Salma',
            'surname' => 'Kikwete',
            'phone1' => '+255755400400',
            'email' => 'salma@farms.co.tz',
            'gender' => 'female',
            'countryId' => $this->country->id,
            'farmerType' => 'individual',
            'status' => 'active',
        ]);

        $farm = Farm::create([
            'farmerId' => $farmer->id,
            'uuid' => 'farm-uuid-4001',
            'referenceNo' => 'FRM-4001',
            'name' => 'Salma Grazing Estate',
            'size' => 80,
            'sizeUnit' => 'acre',
            'physicalAddress' => 'Monduli',
            'countryId' => $this->country->id,
            'status' => 'active',
        ]);

        $officer = ExtensionOfficer::create([
            'firstName' => 'Joseph',
            'lastName' => 'Lyimo',
            'email' => 'joseph.lyimo@vet.go.tz',
            'phone' => '+255766400400',
            'password' => Hash::make('password123'),
            'gender' => 'male',
            'countryId' => $this->country->id,
            'regionId' => $this->region->id,
            'districtId' => $this->district->id,
            'wardId' => $this->ward->id,
            'isVerified' => true,
        ]);

        $this->getJson('/api/v1/admin/people/farm-invites/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['extensionOfficers', 'farms']]);

        $createResponse = $this->postJson('/api/v1/admin/people/farm-invites', [
            'extensionOfficerId' => $officer->id,
            'farmId' => $farm->id,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.officerName', 'Joseph Lyimo')
            ->assertJsonPath('data.farmerName', 'Salma Kikwete')
            ->assertJsonPath('data.farmId', $farm->id)
            ->assertJsonPath('data.farmName', 'Salma Grazing Estate');

        $accessCode = (string) $createResponse->json('data.access_code');
        $this->assertStringStartsWith('ACODE-', $accessCode);

        $inviteId = (int) $createResponse->json('data.id');

        $this->patchJson("/api/v1/admin/people/farm-invites/{$inviteId}", [
            'access_code' => 'ACODE-CUSTOM-999',
        ])
            ->assertOk()
            ->assertJsonPath('data.access_code', 'ACODE-CUSTOM-999');

        $this->getJson('/api/v1/admin/people/farm-invites?search=CUSTOM')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inviteId);

        $this->deleteJson("/api/v1/admin/people/farm-invites/{$inviteId}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('extension_officer_farm_invites', ['id' => $inviteId]);
    }

    public function test_system_user_profiles_crud_and_last_admin_protection(): void
    {
        $this->getJson('/api/v1/admin/people/system-user-profiles/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['creators']]);

        // Attempt to delete the only administrator profile must fail with 422
        $this->deleteJson("/api/v1/admin/people/system-user-profiles/{$this->adminProfile->id}")
            ->assertStatus(422);

        $createResponse = $this->postJson('/api/v1/admin/people/system-user-profiles', [
            'firstName' => 'Beatrice',
            'middleName' => 'Anna',
            'lastName' => 'Nyerere',
            'phone' => '+255712999888',
            'address' => 'Dodoma Admin Block',
            'status' => 'active',
            'username' => 'beatrice.nyerere',
            'email' => 'beatrice.nyerere@tagandseal.co.tz',
            'password' => 'SystemPass123!',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.fullName', 'Beatrice Anna Nyerere')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.email', 'beatrice.nyerere@tagandseal.co.tz');

        $profileId = (int) $createResponse->json('data.id');

        $linkedSystemUser = User::query()
            ->where('role', UserRole::SYSTEM_USER)
            ->where('roleId', $profileId)
            ->first();

        $this->assertNotNull($linkedSystemUser);
        $this->assertSame('beatrice.nyerere', $linkedSystemUser->username);
        $this->assertTrue(Hash::check('SystemPass123!', $linkedSystemUser->password));

        $this->patchJson("/api/v1/admin/people/system-user-profiles/{$profileId}", [
            'address' => 'Dar es Salaam Office',
            'status' => 'notActive',
        ])
            ->assertOk()
            ->assertJsonPath('data.address', 'Dar es Salaam Office')
            ->assertJsonPath('data.status', 'notActive');

        $this->assertSame(UserStatus::NOT_ACTIVE, $linkedSystemUser->fresh()->status);

        $this->deleteJson("/api/v1/admin/people/system-user-profiles/{$profileId}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('system_users', ['id' => $profileId]);
        $this->assertDatabaseMissing('users', ['id' => $linkedSystemUser->id]);
    }

    public function test_user_accounts_crud_permissions_sync_and_self_delete_protection(): void
    {
        $permissionId = DB::table('platform_permissions')->value('id');

        $this->getJson('/api/v1/admin/users/options')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'roles',
                    'statuses',
                    'permissions',
                    'farms',
                    'countries',
                    'regions',
                    'districts',
                    'wards',
                    'villages',
                    'streets',
                    'identityCardTypes',
                    'schoolLevels',
                ],
            ]);

        // 1. Create System User Account + SystemUser Profile in one request
        $createResponse = $this->postJson('/api/v1/admin/users', [
            'username' => 'daniel.mtei',
            'email' => 'daniel.mtei@tagandseal.co.tz',
            'password' => 'StrongPass123!',
            'role' => UserRole::SYSTEM_USER,
            'status' => UserStatus::ACTIVE,
            'firstName' => 'Daniel',
            'lastName' => 'Mtei',
            'phone' => '+255713555666',
            'address' => 'Moshi Municipal',
            'platformPermissions' => $permissionId ? [$permissionId] : [],
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.username', 'daniel.mtei')
            ->assertJsonPath('data.profile.name', 'Daniel Mtei')
            ->assertJsonPath('data.profileDetails.firstName', 'Daniel')
            ->assertJsonPath('data.profileDetails.address', 'Moshi Municipal');

        $userId = (int) $createResponse->json('data.id');
        $systemProfileId = (int) $createResponse->json('data.roleId');
        $this->assertDatabaseHas('system_users', [
            'id' => $systemProfileId,
            'firstName' => 'Daniel',
            'lastName' => 'Mtei',
            'phone' => '+255713555666',
        ]);

        // 2. Create Farmer User Account + Farmer Profile in one request
        $farmerUserResponse = $this->postJson('/api/v1/admin/users', [
            'username' => 'zawadi.farmer',
            'email' => 'zawadi.farmer@farms.co.tz',
            'password' => 'FarmerPass123!',
            'role' => UserRole::FARMER,
            'status' => UserStatus::ACTIVE,
            'firstName' => 'Zawadi',
            'surname' => 'Msuya',
            'phone1' => '+255754888999',
            'gender' => 'female',
            'farmerType' => 'individual',
            'countryId' => $this->country->id,
            'regionId' => $this->region->id,
            'districtId' => $this->district->id,
            'wardId' => $this->ward->id,
        ]);

        $farmerUserResponse
            ->assertCreated()
            ->assertJsonPath('data.username', 'zawadi.farmer')
            ->assertJsonPath('data.profile.name', 'Zawadi Msuya');

        $generatedUserFarmerNo = (string) $farmerUserResponse->json('data.profileDetails.farmerNo');
        $this->assertMatchesRegularExpression('/^FMR-\d{8}-\d{4}-\d{3}$/', $generatedUserFarmerNo);

        $farmerProfileId = (int) $farmerUserResponse->json('data.roleId');
        $this->assertDatabaseHas('farmers', [
            'id' => $farmerProfileId,
            'farmerNo' => $generatedUserFarmerNo,
            'firstName' => 'Zawadi',
            'surname' => 'Msuya',
            'email' => 'zawadi.farmer@farms.co.tz',
        ]);

        $this->getJson("/api/v1/admin/users/{$userId}")
            ->assertOk()
            ->assertJsonPath('data.email', 'daniel.mtei@tagandseal.co.tz');

        $this->patchJson("/api/v1/admin/users/{$userId}", [
            'username' => 'daniel.mtei.updated',
            'status' => UserStatus::NOT_ACTIVE,
            'firstName' => 'Daniel Updated',
            'address' => 'Arusha Branch',
        ])
            ->assertOk()
            ->assertJsonPath('data.username', 'daniel.mtei.updated')
            ->assertJsonPath('data.status', UserStatus::NOT_ACTIVE)
            ->assertJsonPath('data.profileDetails.firstName', 'Daniel Updated')
            ->assertJsonPath('data.profileDetails.address', 'Arusha Branch');

        $this->assertDatabaseHas('system_users', [
            'id' => $systemProfileId,
            'firstName' => 'Daniel Updated',
            'address' => 'Arusha Branch',
            'status' => 'notActive',
        ]);

        $this->getJson('/api/v1/admin/users?search=daniel.mtei.updated&role=systemUser&status=notActive&sort=alpha-asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $userId);

        // Self-delete protection
        $this->deleteJson("/api/v1/admin/users/{$this->admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        // Delete second user account
        $this->deleteJson("/api/v1/admin/users/{$userId}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }
}

