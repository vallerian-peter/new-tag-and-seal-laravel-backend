<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferenceMasterDataCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create([
            'name' => 'Tanzania',
            'shortName' => 'TZ',
        ]);

        $systemProfile = SystemUser::create([
            'firstName' => 'Ref',
            'lastName' => 'Admin',
            'phone' => '+255700000333',
            'address' => 'Dodoma HQ',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'username' => 'ref.admin',
            'email' => 'ref.admin@tagandseal.co.tz',
            'password' => Hash::make('Secret123!'),
            'role' => UserRole::SYSTEM_USER,
            'roleId' => $systemProfile->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $farmer = Farmer::create([
            'farmerNo' => 'FMR-2026-333444',
            'firstName' => 'Neema',
            'surname' => 'Kileo',
            'phone1' => '+255712345699',
            'gender' => 'female',
            'countryId' => $country->id,
            'farmerType' => 'individual',
            'createdBy' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->farm = Farm::create([
            'farmerId' => $farmer->id,
            'uuid' => (string) Str::uuid(),
            'referenceNo' => 'FRM-2026-0002',
            'name' => 'Meru Highlands Farm',
            'size' => 80,
            'sizeUnit' => 'acre',
            'physicalAddress' => 'Arumeru',
            'countryId' => $country->id,
            'status' => 'active',
        ]);

        $this->admin->forceFill(['platform_role' => 'superAdmin'])->save();
        Sanctum::actingAs($this->admin);
    }

    public function test_all_nine_reference_master_data_resources_crud(): void
    {
        // 1. Vaccine Types
        $vaccineTypeRes = $this->postJson('/api/v1/admin/reference/vaccine-types', [
            'name' => 'Viral Vaccine',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Viral Vaccine');

        $vaccineTypeId = (int) $vaccineTypeRes->json('data.id');

        // 2. Vaccines
        $this->getJson('/api/v1/admin/reference/vaccines/options')
            ->assertOk()
            ->assertJsonPath('data.vaccineTypes.0.label', 'Viral Vaccine')
            ->assertJsonPath('data.farms.0.label', 'Meru Highlands Farm')
            ->assertJsonPath('data.farms.0.description', 'FRM-2026-0002 · Farmer: Neema Kileo');

        $vaccineRes = $this->postJson('/api/v1/admin/reference/vaccines', [
            'name' => 'FMD Trivalent Vaccine',
            'farmUuid' => $this->farm->uuid,
            'lot' => 'LOT-2026-084',
            'formulationType' => 'inactivated',
            'dose' => '2 ml SC',
            'status' => 'active',
            'vaccineTypeId' => $vaccineTypeId,
            'vaccineSchedule' => 'regular',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'FMD Trivalent Vaccine')
            ->assertJsonPath('data.farmName', 'Meru Highlands Farm')
            ->assertJsonPath('data.vaccineTypeName', 'Viral Vaccine')
            ->assertJsonPath('data.formulationLabel', 'Inactivated')
            ->assertJsonPath('data.scheduleLabel', 'Regular Schedule');

        $vaccineId = (int) $vaccineRes->json('data.id');

        $this->patchJson("/api/v1/admin/reference/vaccines/{$vaccineId}", [
            'dose' => '3 ml SC',
            'vaccineSchedule' => 'booster',
        ])->assertOk()
            ->assertJsonPath('data.dose', '3 ml SC')
            ->assertJsonPath('data.scheduleLabel', 'Booster');

        // 3. Medicine Types
        $medTypeRes = $this->postJson('/api/v1/admin/reference/medicine-types', [
            'name' => 'Antibiotic',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Antibiotic');
        $medTypeId = (int) $medTypeRes->json('data.id');

        // 4. Marking Types
        $markingRes = $this->postJson('/api/v1/admin/reference/marking-types', [
            'name' => 'Hot Iron Branding',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Hot Iron Branding');
        $markingId = (int) $markingRes->json('data.id');

        // 5. Tail Docking Methods
        $dockingRes = $this->postJson('/api/v1/admin/reference/tail-docking-methods', [
            'name' => 'Surgical Docking',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Surgical Docking');
        $dockingId = (int) $dockingRes->json('data.id');

        // 6. Teeth Clipping Methods
        $clippingRes = $this->postJson('/api/v1/admin/reference/teeth-clipping-methods', [
            'name' => 'Precision Grinding',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Precision Grinding');
        $clippingId = (int) $clippingRes->json('data.id');

        // 7. ID Card Types
        $idCardRes = $this->postJson('/api/v1/admin/reference/id-card-types', [
            'name' => 'National ID (NIDA)',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'National ID (NIDA)');
        $idCardId = (int) $idCardRes->json('data.id');

        // 8. School Levels
        $schoolRes = $this->postJson('/api/v1/admin/reference/school-levels', [
            'name' => 'University Degree',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'University Degree');
        $schoolId = (int) $schoolRes->json('data.id');

        // 9. Legal Statuses
        $legalRes = $this->postJson('/api/v1/admin/reference/legal-statuses', [
            'name' => 'Cooperative Society',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Cooperative Society');
        $legalId = (int) $legalRes->json('data.id');

        // Verify deletion across all 9 resources
        $this->deleteJson("/api/v1/admin/reference/vaccines/{$vaccineId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/vaccine-types/{$vaccineTypeId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/medicine-types/{$medTypeId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/marking-types/{$markingId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/tail-docking-methods/{$dockingId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/teeth-clipping-methods/{$clippingId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/id-card-types/{$idCardId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/school-levels/{$schoolId}")->assertOk();
        $this->deleteJson("/api/v1/admin/reference/legal-statuses/{$legalId}")->assertOk();
    }
}
