<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AnimalSpecies;
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

class AnimalsAndLivestockCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Farm $farm;

    private AnimalSpecies $dynamicSpecies;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create([
            'name' => 'Tanzania',
            'shortName' => 'TZ',
        ]);

        $systemProfile = SystemUser::create([
            'firstName' => 'Animal',
            'lastName' => 'Admin',
            'phone' => '+255700000222',
            'address' => 'Dodoma HQ',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'username' => 'animal.admin',
            'email' => 'animal.admin@tagandseal.co.tz',
            'password' => Hash::make('Secret123!'),
            'role' => UserRole::SYSTEM_USER,
            'roleId' => $systemProfile->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $farmer = Farmer::create([
            'farmerNo' => 'FMR-20261030-1111-222',
            'firstName' => 'Juma',
            'surname' => 'Mwakasege',
            'phone1' => '+255712345678',
            'gender' => 'male',
            'countryId' => $country->id,
            'farmerType' => 'individual',
            'createdBy' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->farm = Farm::create([
            'farmerId' => $farmer->id,
            'uuid' => (string) Str::uuid(),
            'referenceNo' => 'FRM-2026-0001',
            'name' => 'Kilimanjaro Dairy Ranch',
            'size' => 120,
            'sizeUnit' => 'acre',
            'physicalAddress' => 'Moshi Rural',
            'countryId' => $country->id,
            'status' => 'active',
        ]);

        $this->dynamicSpecies = AnimalSpecies::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'cattle',
            'name' => 'Cattle',
            'default_subject_mode' => 'individual',
            'status' => 'published',
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_animals_and_livestock_resources_crud_and_soft_deletes(): void
    {
        // 1. Animal Types
        $typeStore = $this->postJson('/api/v1/admin/animals/animal-types', [
            'name' => 'Cattle',
        ])->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'Cattle');

        $typeId = (int) $typeStore->json('data.id');

        $this->patchJson("/api/v1/admin/animals/animal-types/{$typeId}", [
            'name' => 'Dairy Cattle',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Dairy Cattle');

        // 2. Species
        $speciesStore = $this->postJson('/api/v1/admin/animals/species', [
            'name' => 'Bos taurus',
            'livestockTypeId' => $typeId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Bos taurus')
            ->assertJsonPath('data.livestockTypeId', $typeId)
            ->assertJsonPath('data.livestockTypeName', 'Dairy Cattle');

        $speciesId = (int) $speciesStore->json('data.id');

        // 3. Breeds
        $breedStore = $this->postJson('/api/v1/admin/animals/breeds', [
            'name' => 'Friesian',
            'group' => 'Exotic Dairy',
            'livestockTypeId' => $typeId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Friesian')
            ->assertJsonPath('data.group', 'Exotic Dairy')
            ->assertJsonPath('data.livestockTypeId', $typeId)
            ->assertJsonPath('data.livestockTypeName', 'Dairy Cattle');

        $breedId = (int) $breedStore->json('data.id');

        // 4. Acquisition Methods
        $methodStore = $this->postJson('/api/v1/admin/animals/acquisition-methods', [
            'name' => 'Born on farm',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Born on farm');

        $methodId = (int) $methodStore->json('data.id');

        // 5. Legacy Livestock (with soft delete, restore, and permanent delete)
        $this->getJson('/api/v1/admin/animals/legacy-livestock/options')
            ->assertOk()
            ->assertJsonPath('data.livestockTypes.0.label', 'Dairy Cattle')
            ->assertJsonPath('data.species.0.label', 'Bos taurus')
            ->assertJsonPath('data.breeds.0.label', 'Friesian');

        $livestockStore = $this->postJson('/api/v1/admin/animals/legacy-livestock', [
            'name' => 'Subira',
            'farmUuid' => $this->farm->uuid,
            'identificationNumber' => 'TZS-2026-0099',
            'rfidTagId' => '982000991122',
            'livestockTypeId' => $typeId,
            'speciesId' => $speciesId,
            'breedId' => $breedId,
            'gender' => 'female',
            'dateOfBirth' => '2024-03-15',
            'isIdentified' => true,
            'status' => 'active',
            'livestockObtainedMethodId' => $methodId,
            'weightAsOnRegistration' => 285.5,
            'primaryColor' => 'black',
            'secondaryColor' => 'white',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Subira')
            ->assertJsonPath('data.identificationNumber', 'TZS-2026-0099')
            ->assertJsonPath('data.farmName', 'Kilimanjaro Dairy Ranch')
            ->assertJsonPath('data.speciesName', 'Bos taurus')
            ->assertJsonPath('data.breedName', 'Friesian')
            ->assertJsonPath('data.livestockTypeName', 'Dairy Cattle')
            ->assertJsonPath('data.obtainedMethodName', 'Born on farm')
            ->assertJsonPath('data.primaryColor', 'black');

        $livestockId = (int) $livestockStore->json('data.id');

        // Soft delete Legacy Livestock
        $this->deleteJson("/api/v1/admin/animals/legacy-livestock/{$livestockId}")
            ->assertOk();

        $this->getJson('/api/v1/admin/animals/legacy-livestock?tab=all')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.deletedCount', 1);

        $this->getJson('/api/v1/admin/animals/legacy-livestock?tab=deleted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $livestockId);

        // Restore Legacy Livestock
        $this->patchJson("/api/v1/admin/animals/legacy-livestock/{$livestockId}?restore=1", [
            'action' => 'restore',
        ])->assertOk()
            ->assertJsonPath('data.id', $livestockId);

        $this->getJson('/api/v1/admin/animals/legacy-livestock?tab=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 6. Dynamic Animals (AnimalSubject + primary tag + soft delete + restore + force delete)
        $animalStore = $this->postJson('/api/v1/admin/animals/animals', [
            'display_name' => 'Malkia #104',
            'primary_tag' => 'TZ-RFID-00104',
            'farm_uuid' => $this->farm->uuid,
            'species_uuid' => $this->dynamicSpecies->uuid,
            'unit_type' => 'individual',
            'sex_code' => 'female',
            'birth_or_start_date' => '2025-01-10',
            'quantity' => 1,
            'current_stage_code' => 'heifer',
            'lifecycle_status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.display_name', 'Malkia #104')
            ->assertJsonPath('data.primary_tag', 'TZ-RFID-00104')
            ->assertJsonPath('data.identifiersText', 'TZ-RFID-00104')
            ->assertJsonPath('data.farmName', 'Kilimanjaro Dairy Ranch')
            ->assertJsonPath('data.speciesName', 'Cattle');

        $animalId = (int) $animalStore->json('data.id');

        // Update Animal Subject tag & stage
        $this->patchJson("/api/v1/admin/animals/animals/{$animalId}", [
            'primary_tag' => 'TZ-RFID-00104-B',
            'current_stage_code' => 'lactating',
        ])->assertOk()
            ->assertJsonPath('data.primary_tag', 'TZ-RFID-00104-B')
            ->assertJsonPath('data.current_stage_code', 'lactating');

        // Soft delete Dynamic Animal
        $this->deleteJson("/api/v1/admin/animals/animals/{$animalId}")
            ->assertOk();

        $this->getJson('/api/v1/admin/animals/animals?tab=deleted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $animalId);

        // Restore Dynamic Animal
        $this->patchJson("/api/v1/admin/animals/animals/{$animalId}?restore=1", [
            'action' => 'restore',
        ])->assertOk();

        // Force delete Dynamic Animal and Legacy Livestock
        $this->deleteJson("/api/v1/admin/animals/animals/{$animalId}?force=1")->assertOk();
        $this->deleteJson("/api/v1/admin/animals/legacy-livestock/{$livestockId}?force=1")->assertOk();

        // Clean up catalogue resources
        $this->deleteJson("/api/v1/admin/animals/acquisition-methods/{$methodId}")->assertOk();
        $this->deleteJson("/api/v1/admin/animals/breeds/{$breedId}")->assertOk();
        $this->deleteJson("/api/v1/admin/animals/species/{$speciesId}")->assertOk();
        $this->deleteJson("/api/v1/admin/animals/animal-types/{$typeId}")->assertOk();
    }
}

