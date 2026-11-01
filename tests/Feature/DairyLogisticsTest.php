<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\DairyFactory;
use App\Models\DairyFactoryTank;
use App\Models\DairyTrip;
use App\Models\DairyTripRoute;
use App\Models\District;
use App\Models\MilkCollectionCentre;
use App\Models\Region;
use App\Models\SystemUser;
use App\Models\TransporterCompany;
use App\Models\TransporterVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DairyLogisticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Region $region;
    protected District $district;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create([
            'name' => 'Tanzania',
            'shortName' => 'TZ',
        ]);

        $this->region = Region::create([
            'countryId' => $country->id,
            'name' => 'Mbeya',
            'shortName' => 'MBY',
        ]);

        $this->district = District::create([
            'regionId' => $this->region->id,
            'name' => 'Rungwe',
        ]);

        $profile = SystemUser::create([
            'firstName' => 'System',
            'lastName' => 'Admin',
            'phone' => '+255700000001',
            'address' => 'Dodoma HQ',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'System Admin',
            'username' => 'admin_test',
            'phone' => '+255700000001',
            'email' => 'admin_logistics@test.tz',
            'password' => Hash::make('Secret123!'),
            'role' => UserRole::SYSTEM_USER,
            'roleId' => $profile->id,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_transporter_registration_and_admin_approval(): void
    {
        // 1. Register transporter
        $response = $this->postJson('/api/v1/admin/logistics/transporters', [
            'name' => 'Highland Milk Carriers Ltd',
            'license_number' => 'LATRA-TEST-' . time(),
            'tin_number' => '100-200-300',
            'contact_person' => 'Daniel Mwita',
            'phone' => '+255 788 112 233',
            'email' => 'daniel@highlandmilk.tz',
            'document_urls' => ['license' => 'https://docs.tagandseal.tz/licenses/highland.pdf'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.approval_status', 'pending');

        $transporterUuid = $response->json('data.uuid');

        // 2. Admin approves transporter
        $approveResponse = $this->postJson("/api/v1/admin/logistics/transporters/{$transporterUuid}/approve", [
            'remarks' => 'All documents verified with LATRA and Dairy Board.',
        ]);

        $approveResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.approval_status', 'approved');

        $this->assertDatabaseHas('transporter_companies', [
            'uuid' => $transporterUuid,
            'approval_status' => 'approved',
        ]);
    }

    public function test_factory_registration_and_admin_rejection(): void
    {
        $response = $this->postJson('/api/v1/admin/logistics/factories', [
            'name' => 'Southern Highlands Dairies',
            'factory_code' => 'FAC-TEST-' . time(),
            'registration_number' => 'BRELA-TEST-001',
            'contact_name' => 'Sarah Lyimo',
            'contact_phone' => '+255 712 345 678',
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
            'total_storage_capacity_liters' => 60000.00,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.approval_status', 'pending');

        $factoryUuid = $response->json('data.uuid');

        // Admin rejects factory due to missing docs
        $rejectResponse = $this->postJson("/api/v1/admin/logistics/factories/{$factoryUuid}/reject", [
            'remarks' => 'Missing valid TBS food hygiene certificate.',
        ]);

        $rejectResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.approval_status', 'rejected');

        $this->assertDatabaseHas('dairy_factories', [
            'uuid' => $factoryUuid,
            'approval_status' => 'rejected',
        ]);
    }

    public function test_trip_creation_and_mid_trip_route_addition(): void
    {
        $transporter = TransporterCompany::create([
            'name' => 'Highland Haulers',
            'license_number' => 'LATRA-9911',
            'contact_person' => 'Juma',
            'phone' => '+255 777 111 222',
            'approval_status' => 'approved',
        ]);

        $vehicle = TransporterVehicle::create([
            'transporter_company_uuid' => $transporter->uuid,
            'registration_number' => 'T 112 ABC',
            'tanker_capacity_liters' => 15000.00,
            'approval_status' => 'approved',
        ]);

        $factory = DairyFactory::create([
            'name' => 'Iringa Milk Plant',
            'factory_code' => 'FAC-IRG-01',
            'registration_number' => 'BRELA-IRG-99',
            'contact_name' => 'Kassim',
            'contact_phone' => '+255 711 222 333',
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
            'approval_status' => 'approved',
        ]);

        $mcc = MilkCollectionCentre::create([
            'centre_code' => 'MCC-RNG-01',
            'name' => 'Rungwe MCC',
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
        ]);

        // 1. Create Trip with initial route
        $createTripResponse = $this->postJson('/api/v1/admin/logistics/trips', [
            'transporter_company_uuid' => $transporter->uuid,
            'driver_user_id' => $this->admin->id,
            'vehicle_uuid' => $vehicle->uuid,
            'destination_factory_uuid' => $factory->uuid,
            'routes' => [
                [
                    'mcc_uuid' => $mcc->uuid,
                    'liters' => 3500.00,
                    'temperature_celsius' => 4.0,
                    'security_seal_numbers' => ['SEAL-001', 'SEAL-002'],
                ],
            ],
        ]);

        $createTripResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_liters', '3500.00');

        $tripUuid = $createTripResponse->json('data.uuid');

        // 2. Start Trip
        $startResponse = $this->postJson("/api/v1/admin/logistics/trips/{$tripUuid}/start");
        $startResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'in_transit');

        // 3. Dynamic Mid-Trip Route Addition (AddRoutes pattern)
        $addRouteResponse = $this->postJson("/api/v1/admin/logistics/trips/{$tripUuid}/routes", [
            'routes' => [
                [
                    'mcc_uuid' => $mcc->uuid,
                    'liters' => 1500.00,
                    'temperature_celsius' => 3.8,
                    'security_seal_numbers' => ['SEAL-MID-003'],
                ],
            ],
        ]);

        $addRouteResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_liters', '5000.00');

        // Verify the newly added route has added_mid_trip = true and destination set
        $this->assertDatabaseHas('dairy_trip_routes', [
            'dairy_trip_uuid' => $tripUuid,
            'liters' => 1500.00,
            'destination_factory_uuid' => $factory->uuid,
            'added_mid_trip' => true,
        ]);

        // 4. Test Route Destination Mismatch is rejected
        $otherFactory = DairyFactory::create([
            'name' => 'Morogoro Dairy Plant',
            'factory_code' => 'FAC-MORO-01',
            'registration_number' => 'BRELA-MORO-99',
            'contact_name' => 'Musa',
            'contact_phone' => '+255 744 111 000',
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
            'approval_status' => 'approved',
        ]);

        $mismatchResponse = $this->postJson("/api/v1/admin/logistics/trips/{$tripUuid}/routes", [
            'routes' => [
                [
                    'mcc_uuid' => $mcc->uuid,
                    'destination_factory_uuid' => $otherFactory->uuid,
                    'liters' => 500.00,
                ],
            ],
        ]);

        $mismatchResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        // 5. Test Tanker Capacity Overflow is rejected
        $overflowResponse = $this->postJson("/api/v1/admin/logistics/trips/{$tripUuid}/routes", [
            'routes' => [
                [
                    'mcc_uuid' => $mcc->uuid,
                    'liters' => 20000.00, // Vehicle capacity is 15,000, current cargo is 5,000
                ],
            ],
        ]);

        $overflowResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        // 6. Test Transporter Boss Trips view
        $bossTripsResponse = $this->getJson("/api/v1/admin/logistics/transporters/{$transporter->uuid}/trips");
        $bossTripsResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.trips.0.uuid', $tripUuid);
    }
    public function test_factory_operator_receives_milk_and_stores_in_bulk_tank(): void
    {
        $factory = DairyFactory::create([
            'name' => 'Mbeya Processing Plant',
            'factory_code' => 'FAC-MBY-01',
            'registration_number' => 'BRELA-MBY-11',
            'contact_name' => 'Lucas',
            'contact_phone' => '+255 755 444 333',
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
            'approval_status' => 'approved',
        ]);

        $tank = DairyFactoryTank::create([
            'factory_uuid' => $factory->uuid,
            'tank_code' => 'SILO-01',
            'capacity_liters' => 40000.00,
            'current_stock_liters' => 5000.00,
            'target_temperature_celsius' => 3.8,
            'status' => 'active_chilling',
        ]);

        $initialStock = (float) $tank->current_stock_liters;

        $receiveResponse = $this->postJson("/api/v1/admin/logistics/factories/{$factory->uuid}/receive", [
            'factory_tank_uuid' => $tank->uuid,
            'received_volume_liters' => 4950.00,
            'arrival_temperature_celsius' => 4.2,
            'seals_intact' => true,
            'factory_qa_status' => 'accepted_full',
            'notes' => 'Bulk tanker intake pumped to Silo 1 after rapid lab clearance.',
        ]);

        $receiveResponse->assertStatus(201)
            ->assertJsonPath('success', true);

        // Verify stock increased in tank
        $tank->refresh();
        $this->assertEquals($initialStock + 4950.00, (float) $tank->current_stock_liters);

        // Verify factory stock movement ledger record
        $this->assertDatabaseHas('dairy_factory_stock_movements', [
            'factory_uuid' => $factory->uuid,
            'factory_tank_uuid' => $tank->uuid,
            'movement_type' => 'tanker_intake',
        ]);
    }
    public function test_admin_commerce_and_dairy_resources_are_available(): void
    {
        $this->getJson('/api/v1/admin/commerce/listings')->assertOk()->assertJsonPath('status', true);
        $this->getJson('/api/v1/admin/dairy/centres')->assertOk()->assertJsonPath('status', true);

        $factory = DairyFactory::create([
            'name' => 'Resource Test Plant',
            'factory_code' => 'FAC-RESOURCE-01',
            'registration_number' => 'BRELA-RESOURCE-01',
            'contact_name' => 'Test Manager',
            'contact_phone' => '+255 700 000 001',
            'region_id' => $this->region->id,
            'district_id' => $this->district->id,
        ]);

        $this->getJson("/api/v1/admin/dairy/factories/{$factory->uuid}")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.uuid', $factory->uuid);
        $this->getJson("/api/v1/admin/logistics/factories/{$factory->uuid}/inventory")
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
