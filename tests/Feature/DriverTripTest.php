<?php

namespace Tests\Feature;

use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\TransporterVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverTripTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $driverUser;

    private TransporterCompany $company;

    private TransporterVehicle $vehicle;

    private array $handover = ['welcoming_operator_uuid' => 'member-1'];

    protected function setUp(): void
    {
        parent::setUp();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Mbeya', 'shortName' => 'MB', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Rungwe', 'regionId' => $region]);
        DB::table('cooperative_collection_centres')->insert([
            ['uuid' => 'centre-1', 'amcos_uuid' => 'amcos-1', 'name' => 'Kiwira Centre', 'centre_code' => 'C1'],
            ['uuid' => 'centre-2', 'amcos_uuid' => 'amcos-1', 'name' => 'Tukuyu Centre', 'centre_code' => 'C2'],
        ]);
        foreach (['factory-1' => 'Mbeya Dairy', 'factory-2' => 'Njombe Dairy'] as $uuid => $name) {
            DB::table('dairy_factories')->insert([
                'uuid' => $uuid, 'name' => $name, 'factory_code' => strtoupper($uuid), 'registration_number' => "REG-{$uuid}",
                'contact_name' => 'Plant', 'contact_phone' => '0700000000', 'region_id' => $region, 'district_id' => $district,
                'approval_status' => 'approved', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->manager = User::factory()->create();
        $this->company = TransporterCompany::create([
            'name' => 'Carrier', 'license_number' => 'CARRIER-1', 'contact_person' => 'Manager', 'phone' => '0712345678',
            'manager_user_id' => $this->manager->id, 'approval_status' => 'approved', 'is_active' => true,
        ]);
        $this->vehicle = TransporterVehicle::create([
            'transporter_company_uuid' => $this->company->uuid, 'registration_number' => 'T 123 ABC',
            'tanker_capacity_liters' => 1000, 'is_active' => true,
        ]);
        $this->driverUser = User::factory()->create();
        TransporterDriver::create([
            'transporter_company_uuid' => $this->company->uuid, 'user_id' => $this->driverUser->id,
            'first_name' => 'Juma', 'last_name' => 'Ali', 'phone' => '+255766111222', 'driver_license_number' => 'L-1',
            'assigned_vehicle_uuid' => $this->vehicle->uuid, 'is_active' => true,
        ]);
        DB::table('factory_operators')->insert([
            'uuid' => 'member-1', 'factory_uuid' => 'factory-1', 'user_id' => User::factory()->create()->id,
            'first_name' => 'Neema', 'last_name' => 'Mwakyusa', 'role' => 'operator', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dispatch(string $uuid, array $overrides = []): void
    {
        DB::table('cooperative_dispatches')->insert([
            'uuid' => $uuid, 'dispatch_code' => strtoupper($uuid), 'batch_number' => "B-{$uuid}", 'centre_uuid' => 'centre-1',
            'quantity' => 300, 'unit' => 'l', 'destination_uuid' => 'factory-1', 'destination_name' => 'Mbeya Dairy',
            'driver_user_id' => $this->driverUser->id, 'status' => 'dispatched', 'dispatched_at' => now(),
            'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ]);
    }

    public function test_driver_creates_a_trip_from_routes_and_drives_it_after_approval(): void
    {
        $this->dispatch('disp-1');
        $this->dispatch('disp-2', ['centre_uuid' => 'centre-2', 'driver_user_id' => null, 'driver_phone' => '0766 111 222']);
        $this->dispatch('disp-3', ['destination_uuid' => 'factory-2', 'destination_name' => 'Njombe Dairy']);
        $this->dispatch('other', ['driver_user_id' => User::factory()->create()->id]);
        Sanctum::actingAs($this->driverUser);

        $routes = $this->getJson('/api/v2/dairy/driver/routes')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['disp-1', 'disp-2', 'disp-3'], array_column($routes, 'route_uuid'));
        $this->assertFalse($routes[0]['is_on_trip']);

        $trip = ['uuid' => (string) Str::uuid(), 'dispatch_uuids' => ['disp-1', 'disp-2']];
        $this->postJson('/api/v2/dairy/driver/trips', [...$trip, 'dispatch_uuids' => ['disp-1', 'disp-3']])
            ->assertUnprocessable();
        $this->postJson('/api/v2/dairy/driver/trips', [...$trip, 'dispatch_uuids' => ['disp-1', 'other']])
            ->assertUnprocessable();

        $this->postJson('/api/v2/dairy/driver/trips', $trip)->assertCreated()
            ->assertJsonPath('data.approval_status', 'pending')
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.vehicle_plate_number', 'T 123 ABC')
            ->assertJsonPath('data.destination_factory_name', 'Mbeya Dairy')
            ->assertJsonPath('data.total_liters', fn ($total) => (float) $total === 600.0)
            ->assertJsonCount(2, 'data.routes')
            ->assertJsonPath('data.routes.1.centre_name', 'Tukuyu Centre');
        $this->postJson('/api/v2/dairy/driver/trips', $trip)->assertOk();
        $this->assertSame(1, DB::table('dairy_trips')->count());
        $this->assertSame(2, DB::table('cooperative_dispatches')->where('trip_uuid', $trip['uuid'])->where('status', 'on_trip')->count());
        $this->postJson('/api/v2/dairy/driver/trips', ['uuid' => (string) Str::uuid(), 'dispatch_uuids' => ['disp-1']])
            ->assertUnprocessable();
        $onTrip = collect($this->getJson('/api/v2/dairy/driver/routes')->json('data'))->firstWhere('route_uuid', 'disp-1');
        $this->assertTrue($onTrip['is_on_trip']);

        $this->postJson("/api/v2/dairy/driver/trips/{$trip['uuid']}/start")->assertUnprocessable();
        $this->postJson("/api/v2/dairy/driver/trips/{$trip['uuid']}/arrive")->assertUnprocessable();

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v2/dairy/transporter/trips/pending-approval')->assertOk()
            ->assertJsonPath('data.0.uuid', $trip['uuid']);
        $this->postJson("/api/v2/dairy/transporter/trips/{$trip['uuid']}/review", ['decision' => 'approved'])->assertOk();

        Sanctum::actingAs($this->driverUser);
        $this->postJson("/api/v2/dairy/driver/trips/{$trip['uuid']}/start")->assertOk();
        $this->postJson("/api/v2/dairy/driver/trips/{$trip['uuid']}/start")->assertOk();
        $this->getJson('/api/v2/dairy/driver/trips')->assertOk()
            ->assertJsonPath('data.0.factory_members.0.name', 'Neema Mwakyusa');
        $this->postJson("/api/v2/dairy/driver/trips/{$trip['uuid']}/arrive")->assertUnprocessable();
        $this->postJson("/api/v2/dairy/driver/trips/{$trip['uuid']}/arrive", $this->handover)->assertOk();
        $this->assertSame(2, DB::table('cooperative_dispatches')->where('trip_uuid', $trip['uuid'])->where('status', 'delivered')->count());
        $this->getJson('/api/v2/dairy/driver/trips')->assertOk()
            ->assertJsonPath('data.0.welcoming_operator_name', 'Neema Mwakyusa')
            ->assertJsonPath('data.0.status', 'arrived')
            ->assertJsonPath('data.0.routes.0.status', 'arrived');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v2/dairy/driver/trips/{$trip['uuid']}/arrive")->assertNotFound();
    }

    public function test_driver_adds_routes_to_the_same_factory_while_the_trip_is_on_the_road(): void
    {
        $this->dispatch('disp-1');
        $this->dispatch('disp-2', ['centre_uuid' => 'centre-2']);
        $this->dispatch('disp-3', ['destination_uuid' => 'factory-2', 'destination_name' => 'Njombe Dairy']);
        $this->dispatch('disp-4', ['quantity' => 500]);
        Sanctum::actingAs($this->driverUser);
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v2/dairy/driver/trips', ['uuid' => $uuid, 'dispatch_uuids' => ['disp-1']])->assertCreated();
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v2/dairy/transporter/trips/{$uuid}/review", ['decision' => 'approved'])->assertOk();
        Sanctum::actingAs($this->driverUser);
        $this->postJson("/api/v2/dairy/driver/trips/{$uuid}/start")->assertOk();

        $add = fn (array $routes) => $this->postJson("/api/v2/dairy/driver/trips/{$uuid}/routes", [
            'uuid' => (string) Str::uuid(), 'dispatch_uuids' => $routes,
        ]);
        $add(['disp-3'])->assertUnprocessable();
        $add(['disp-2', 'disp-4'])->assertUnprocessable();
        $add(['disp-2'])->assertOk()
            ->assertJsonCount(2, 'data.routes')
            ->assertJsonPath('data.routes.1.centre_name', 'Tukuyu Centre')
            ->assertJsonPath('data.routes.1.sequence_order', 2)
            ->assertJsonPath('data.routes.1.added_mid_trip', fn ($added) => (bool) $added)
            ->assertJsonPath('data.total_liters', fn ($total) => (float) $total === 600.0);
        $add(['disp-2'])->assertOk()->assertJsonCount(2, 'data.routes');
        $this->assertDatabaseHas('cooperative_dispatches', ['uuid' => 'disp-2', 'status' => 'on_trip', 'trip_uuid' => $uuid]);

        $this->postJson("/api/v2/dairy/driver/trips/{$uuid}/arrive", $this->handover)->assertOk();
        $this->assertDatabaseHas('cooperative_dispatches', ['uuid' => 'disp-2', 'status' => 'delivered']);
        $this->dispatch('disp-5', ['quantity' => 100]);
        $add(['disp-5'])->assertUnprocessable();
    }

    public function test_capacity_is_checked_and_a_rejected_trip_frees_its_routes(): void
    {
        $this->dispatch('disp-1', ['quantity' => 700]);
        $this->dispatch('disp-2', ['quantity' => 400]);
        Sanctum::actingAs($this->driverUser);
        $this->postJson('/api/v2/dairy/driver/trips', ['uuid' => (string) Str::uuid(), 'dispatch_uuids' => ['disp-1', 'disp-2']])
            ->assertUnprocessable();

        $uuid = (string) Str::uuid();
        $this->postJson('/api/v2/dairy/driver/trips', ['uuid' => $uuid, 'dispatch_uuids' => ['disp-1']])->assertCreated();

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v2/dairy/transporter/trips/{$uuid}/review", ['decision' => 'rejected', 'remarks' => 'Wrong truck'])
            ->assertOk();
        $this->assertDatabaseHas('cooperative_dispatches', ['uuid' => 'disp-1', 'status' => 'dispatched', 'trip_uuid' => null]);
    }
}
