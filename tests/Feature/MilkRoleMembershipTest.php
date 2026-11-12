<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\DairyFactory;
use App\Models\District;
use App\Models\FactoryOperator;
use App\Models\Region;
use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilkRoleMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_memberships_require_authentication(): void
    {
        $this->getJson('/api/v2/dairy/memberships/transportation')->assertUnauthorized();
        $this->getJson('/api/v2/dairy/memberships/factories')->assertUnauthorized();
        $this->postJson('/api/v2/dairy/transporter-registration', [])->assertUnauthorized();
    }

    public function test_transporter_registration_is_pending_owned_and_idempotent(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        DB::table('cooperative_mcus')->insert(['uuid' => 'mcu-1', 'mcu_code' => 'M1', 'name' => 'Union', 'is_active' => true]);
        $data = [
            'uuid' => (string) Str::uuid(),
            'mcu_uuid' => 'mcu-1',
            'name' => 'Milk Transport',
            'license_number' => 'LICENSE-1',
            'tin_number' => '123456789',
            'contact_person' => 'Manager',
            'phone' => '0712345678',
        ];

        $this->postJson('/api/v2/dairy/transporter-registration', $data)
            ->assertCreated()->assertJsonPath('data.approval_status', 'pending');
        $this->assertDatabaseHas('transporter_companies', [
            'uuid' => $data['uuid'], 'manager_user_id' => $user->id, 'approval_status' => 'pending',
        ]);
        $this->postJson('/api/v2/dairy/transporter-registration', $data)->assertOk();
        $this->postJson('/api/v2/dairy/transporter-registration', [...$data, 'name' => 'Changed'])->assertStatus(409);
        $this->postJson('/api/v2/dairy/transporter-registration', [...$data, 'uuid' => (string) Str::uuid()])->assertStatus(409);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v2/dairy/transporter-registration', $data)->assertStatus(409);
        $this->getJson('/api/v2/dairy/memberships/transportation')->assertJsonCount(0, 'data.companies');
    }

    public function test_driver_memberships_are_scoped_and_prevent_company_registration(): void
    {
        $driver = User::factory()->create();
        $other = User::factory()->create();
        $company = TransporterCompany::create([
            'name' => 'Carrier', 'license_number' => 'CARRIER-1', 'contact_person' => 'Manager',
            'phone' => '0712345678', 'approval_status' => 'approved',
        ]);
        TransporterDriver::create([
            'transporter_company_uuid' => $company->uuid, 'user_id' => $driver->id,
            'driver_license_number' => 'DRIVER-1', 'approval_status' => 'pending',
        ]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v2/dairy/memberships/transportation')->assertJsonCount(0, 'data.drivers');

        Sanctum::actingAs($driver);
        $this->getJson('/api/v2/dairy/memberships/transportation')
            ->assertJsonCount(1, 'data.drivers')
            ->assertJsonPath('data.drivers.0.approval_status', 'pending')
            ->assertJsonPath('data.drivers.0.transporter.approval_status', 'approved');
        DB::table('cooperative_mcus')->insert(['uuid' => 'mcu-1', 'mcu_code' => 'M1', 'name' => 'Union', 'is_active' => true]);
        $this->postJson('/api/v2/dairy/transporter-registration', [
            'uuid' => (string) Str::uuid(), 'mcu_uuid' => 'mcu-1', 'name' => 'New Carrier', 'license_number' => 'CARRIER-2',
            'tin_number' => '123-456-780', 'contact_person' => 'Driver', 'phone' => '0712345678',
        ])->assertStatus(409);
    }

    public function test_factory_assignments_are_scoped_and_exclude_inactive_operators(): void
    {
        $operator = User::factory()->create();
        $other = User::factory()->create();
        $country = Country::create(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = Region::create(['name' => 'Mbeya', 'shortName' => 'MB', 'countryId' => $country->id]);
        $district = District::create(['name' => 'Rungwe', 'regionId' => $region->id]);
        $factory = DairyFactory::create([
            'name' => 'Factory', 'factory_code' => 'FACTORY-1', 'registration_number' => 'REG-1',
            'contact_name' => 'Contact', 'contact_phone' => '0712345678',
            'region_id' => $region->id, 'district_id' => $district->id, 'approval_status' => 'approved',
        ]);
        FactoryOperator::create(['factory_uuid' => $factory->uuid, 'user_id' => $operator->id, 'is_active' => true]);
        FactoryOperator::create(['factory_uuid' => $factory->uuid, 'user_id' => $other->id, 'is_active' => false]);

        Sanctum::actingAs($operator);
        $this->getJson('/api/v2/dairy/memberships/factories')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.factory.uuid', $factory->uuid);
        Sanctum::actingAs($other);
        $this->getJson('/api/v2/dairy/memberships/factories')->assertJsonCount(0, 'data');
    }
}
