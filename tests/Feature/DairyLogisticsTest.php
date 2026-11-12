<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\DairyFactory;
use App\Models\District;
use App\Models\MilkCollectionCentre;
use App\Models\Region;
use App\Models\SystemUser;
use App\Models\TransporterCompany;
use App\Models\TransporterVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->admin->forceFill(['platform_role' => 'superAdmin'])->save();
        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_transporter_registration_and_admin_approval(): void
    {
        // 1. Register transporter
        $response = $this->postJson('/api/v1/admin/logistics/transporters', [
            'name' => 'Highland Milk Carriers Ltd',
            'license_number' => 'LATRA-TEST-'.time(),
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
            'factory_code' => 'FAC-TEST-'.time(),
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

    public function test_factory_receipt_reconciles_a_trip_against_its_dispatches(): void
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
        DB::table('dairy_trips')->insert([
            'uuid' => 'trip-1', 'trip_number' => 'TRIP-1', 'transporter_company_uuid' => (string) Str::uuid(),
            'driver_user_id' => $this->admin->id, 'vehicle_uuid' => (string) Str::uuid(), 'vehicle_plate_number' => 'T1',
            'destination_factory_uuid' => $factory->uuid, 'status' => 'arrived', 'approval_status' => 'approved',
            'total_liters' => 600, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['disp-1', 'disp-2'] as $uuid) {
            DB::table('cooperative_dispatches')->insert([
                'uuid' => $uuid, 'dispatch_code' => strtoupper($uuid), 'centre_uuid' => 'centre-1', 'quantity' => 300,
                'destination_uuid' => $factory->uuid, 'destination_name' => $factory->name, 'status' => 'delivered',
                'trip_uuid' => 'trip-1', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $receipt = [
            'trip_uuid' => 'trip-1', 'received_volume_liters' => 590, 'accepted_volume_liters' => 580,
            'arrival_temperature_celsius' => 4.2, 'seals_intact' => true, 'factory_qa_status' => 'accepted_with_deduction',
        ];
        $url = "/api/v1/admin/logistics/factories/{$factory->uuid}/receive";

        $this->postJson($url, $receipt)->assertUnprocessable();
        $this->postJson($url, [...$receipt, 'spillage_reason' => 'Spilt at loading'])->assertCreated();
        $this->postJson($url, [...$receipt, 'spillage_reason' => 'Again'])->assertStatus(409);

        $this->assertDatabaseHas('milk_factory_deliveries', [
            'trip_uuid' => 'trip-1', 'factory_uuid' => $factory->uuid, 'dispatched_volume_liters' => 600,
            'received_volume_liters' => 590, 'accepted_volume_liters' => 580, 'discrepancy_liters' => -10,
        ]);
        $this->assertDatabaseHas('dairy_trips', ['uuid' => 'trip-1', 'status' => 'completed']);
        $this->assertSame(2, DB::table('cooperative_dispatches')->where('status', 'reconciled')->count());

        $this->getJson('/api/v1/admin/dairy/deliveries')->assertOk()
            ->assertJsonPath('data.data.0.trip_number', 'TRIP-1')
            ->assertJsonPath('data.data.0.factory_name', 'Mbeya Processing Plant');
        $this->getJson('/api/v1/admin/dairy/dispatches')->assertOk()
            ->assertJsonPath('data.data.0.trip_number', 'TRIP-1');
        $this->getJson('/api/v1/admin/dairy/trips')->assertOk()
            ->assertJsonPath('data.data.0.dispatches.0.dispatch_code', 'DISP-1');
        $this->getJson('/api/v1/admin/dairy/dispatches?search=DISP-2&status=reconciled&sort=asc')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.dispatch_code', 'DISP-2');
        $this->getJson('/api/v1/admin/dairy/dispatches?status=dispatched')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/admin/dairy/deliveries?search=Mbeya&status=accepted_with_deduction')->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/admin/dairy/deliveries?status=rejected_spoiled')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/admin/dairy/trips?search=T1&sort=asc')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/admin/dairy/trips?search=missing')->assertOk()->assertJsonPath('data.total', 0);

    }

    public function test_milk_intake_filters_and_sorting_apply_before_pagination(): void
    {
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => 'centre-filter', 'amcos_uuid' => 'amcos-1', 'name' => 'Test Collection', 'centre_code' => 'MCC-FILTER',
        ]);
        DB::table('cooperative_farmer_memberships')->insert([
            'uuid' => 'member-1', 'amcos_uuid' => 'amcos-1', 'farmer_user_id' => $this->admin->id, 'member_number' => 'M-1',
            'farmer_name' => 'Asha Mollel', 'farmer_phone' => '0712000111', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(0, 12) as $i) {
            DB::table('cooperative_intakes')->insert([
                'uuid' => 'intake-'.$i, 'receipt_number' => 'RCP-'.$i, 'unit' => 'l',
                'commodity_code' => $i === 12 ? 'maize' : 'milk', 'amcos_uuid' => 'amcos-1', 'centre_uuid' => 'centre-filter',
                'farmer_user_id' => $this->admin->id, 'clerk_user_id' => $this->admin->id,
                'gross_quantity' => 20, 'accepted_quantity' => $i === 11 ? 18 : 20,
                'rejected_quantity' => $i === 11 ? 2 : 0, 'temperature_celsius' => 4,
                'intake_timestamp' => now()->addMinutes($i), 'created_at' => now()->addMinutes($i), 'updated_at' => now(),
            ]);
        }
        $base = '/api/v1/admin/dairy/intakes';
        $this->getJson($base.'?per_page=10&sort=asc')->assertOk()
            ->assertJsonPath('data.total', 12)->assertJsonCount(10, 'data.data')
            ->assertJsonPath('data.data.0.uuid', 'intake-0')
            ->assertJsonPath('data.data.0.farmer_name', 'Asha Mollel')
            ->assertJsonPath('data.data.0.centre_name', 'Test Collection')
            ->assertJsonPath('data.meta.total_rejected_liters', 2);
        $this->getJson($base.'?per_page=10&sort=desc')->assertOk()->assertJsonPath('data.data.0.uuid', 'intake-11');
        $this->getJson($base.'?search=Test%20Collection&status=rejected')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.uuid', 'intake-11');
        $this->getJson($base.'?search=Asha&status=accepted')->assertOk()->assertJsonPath('data.total', 11);
        $this->getJson($base.'?search=RCP-3')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($base.'?search=missing')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($base.'/RCP-11')->assertOk()->assertJsonPath('data.rejected_quantity', fn ($v) => (float) $v === 2.0);
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
        $this->getJson("/api/v1/admin/logistics/factories/{$factory->uuid}/receipts")
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
