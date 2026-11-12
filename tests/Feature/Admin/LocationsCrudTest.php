<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationsCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $systemProfile = SystemUser::create([
            'firstName' => 'Location',
            'lastName' => 'Admin',
            'phone' => '+255700000111',
            'address' => 'Dodoma HQ',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'username' => 'location.admin',
            'email' => 'location.admin@tagandseal.co.tz',
            'password' => Hash::make('Secret123!'),
            'role' => UserRole::SYSTEM_USER,
            'roleId' => $systemProfile->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->admin->forceFill(['platform_role' => 'superAdmin'])->save();
        Sanctum::actingAs($this->admin);
    }

    public function test_full_hierarchy_locations_crud_from_country_to_street(): void
    {
        // 1. Countries (Top level)
        $countryStore = $this->postJson('/api/v1/admin/locations/countries', [
            'name' => 'Tanzania',
            'shortName' => 'TZ',
        ])->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'Tanzania')
            ->assertJsonPath('data.shortName', 'TZ');

        $countryId = (int) $countryStore->json('data.id');

        $this->patchJson("/api/v1/admin/locations/countries/{$countryId}", [
            'name' => 'United Republic of Tanzania',
            'shortName' => 'TZA',
        ])->assertOk()
            ->assertJsonPath('data.name', 'United Republic of Tanzania')
            ->assertJsonPath('data.shortName', 'TZA');

        // 2. Regions
        $this->getJson('/api/v1/admin/locations/regions/options')
            ->assertOk()
            ->assertJsonPath('data.countries.0.value', (string) $countryId)
            ->assertJsonPath('data.countries.0.label', 'United Republic of Tanzania');

        $regionStore = $this->postJson('/api/v1/admin/locations/regions', [
            'name' => 'Arusha',
            'shortName' => 'AR',
            'countryId' => $countryId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Arusha')
            ->assertJsonPath('data.shortName', 'AR')
            ->assertJsonPath('data.countryId', $countryId)
            ->assertJsonPath('data.countryName', 'United Republic of Tanzania');

        $regionId = (int) $regionStore->json('data.id');

        $this->getJson("/api/v1/admin/locations/regions?parentId={$countryId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.countryName', 'United Republic of Tanzania');

        // 3. Districts
        $this->getJson('/api/v1/admin/locations/districts/options')
            ->assertOk()
            ->assertJsonPath('data.regions.0.value', (string) $regionId)
            ->assertJsonPath('data.regions.0.label', 'Arusha');

        $districtStore = $this->postJson('/api/v1/admin/locations/districts', [
            'name' => 'Arusha Urban',
            'regionId' => $regionId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Arusha Urban')
            ->assertJsonPath('data.regionId', $regionId)
            ->assertJsonPath('data.regionName', 'Arusha')
            ->assertJsonPath('data.countryName', 'United Republic of Tanzania');

        $districtId = (int) $districtStore->json('data.id');

        // 4. Divisions
        $this->getJson('/api/v1/admin/locations/divisions/options')
            ->assertOk()
            ->assertJsonPath('data.districts.0.value', (string) $districtId)
            ->assertJsonPath('data.districts.0.label', 'Arusha Urban');

        $divisionStore = $this->postJson('/api/v1/admin/locations/divisions', [
            'name' => 'Themi Division',
            'districtId' => $districtId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Themi Division')
            ->assertJsonPath('data.districtId', $districtId)
            ->assertJsonPath('data.districtName', 'Arusha Urban')
            ->assertJsonPath('data.regionName', 'Arusha')
            ->assertJsonPath('data.countryName', 'United Republic of Tanzania');

        $divisionId = (int) $divisionStore->json('data.id');

        $this->patchJson("/api/v1/admin/locations/divisions/{$divisionId}", [
            'name' => 'Themi Central Division',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Themi Central Division')
            ->assertJsonPath('data.districtName', 'Arusha Urban');

        // 5. Wards
        $wardStore = $this->postJson('/api/v1/admin/locations/wards', [
            'name' => 'Sekei',
            'districtId' => $districtId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sekei')
            ->assertJsonPath('data.districtId', $districtId)
            ->assertJsonPath('data.districtName', 'Arusha Urban')
            ->assertJsonPath('data.regionName', 'Arusha')
            ->assertJsonPath('data.countryName', 'United Republic of Tanzania');

        $wardId = (int) $wardStore->json('data.id');

        // 6. Villages
        $this->getJson('/api/v1/admin/locations/villages/options')
            ->assertOk()
            ->assertJsonPath('data.wards.0.value', (string) $wardId)
            ->assertJsonPath('data.wards.0.label', 'Sekei');

        $villageStore = $this->postJson('/api/v1/admin/locations/villages', [
            'name' => 'Sekei Village',
            'wardId' => $wardId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sekei Village')
            ->assertJsonPath('data.wardId', $wardId)
            ->assertJsonPath('data.wardName', 'Sekei')
            ->assertJsonPath('data.districtName', 'Arusha Urban')
            ->assertJsonPath('data.regionName', 'Arusha')
            ->assertJsonPath('data.countryName', 'United Republic of Tanzania');

        $villageId = (int) $villageStore->json('data.id');

        // 7. Streets (Bottom level)
        $streetStore = $this->postJson('/api/v1/admin/locations/streets', [
            'name' => 'Sanawari Street',
            'wardId' => $wardId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sanawari Street')
            ->assertJsonPath('data.wardId', $wardId)
            ->assertJsonPath('data.wardName', 'Sekei')
            ->assertJsonPath('data.districtName', 'Arusha Urban')
            ->assertJsonPath('data.regionName', 'Arusha')
            ->assertJsonPath('data.countryName', 'United Republic of Tanzania');

        $streetId = (int) $streetStore->json('data.id');

        // Verify Ward counts reflect linked Village and Street
        $this->getJson("/api/v1/admin/locations/wards/{$wardId}")
            ->assertOk()
            ->assertJsonPath('data.villagesCount', 1)
            ->assertJsonPath('data.streetsCount', 1);

        // Verify search across ancestor names works
        $this->getJson('/api/v1/admin/locations/streets?search=Sekei')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sanawari Street');

        // Verify deletion from bottom to top
        $this->deleteJson("/api/v1/admin/locations/streets/{$streetId}")->assertOk();
        $this->deleteJson("/api/v1/admin/locations/villages/{$villageId}")->assertOk();
        $this->deleteJson("/api/v1/admin/locations/divisions/{$divisionId}")->assertOk();
        $this->deleteJson("/api/v1/admin/locations/wards/{$wardId}")->assertOk();
        $this->deleteJson("/api/v1/admin/locations/districts/{$districtId}")->assertOk();
        $this->deleteJson("/api/v1/admin/locations/regions/{$regionId}")->assertOk();
        $this->deleteJson("/api/v1/admin/locations/countries/{$countryId}")->assertOk();
    }
}

