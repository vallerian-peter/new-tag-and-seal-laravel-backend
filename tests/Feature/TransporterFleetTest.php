<?php

namespace Tests\Feature;

use App\Models\TransporterCompany;
use App\Models\User;
use App\Services\Auth\LoginMembershipSnapshot;
use App\Services\PhoneAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransporterFleetTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private TransporterCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Mbeya', 'shortName' => 'MB', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Rungwe', 'regionId' => $region]);
        DB::table('cooperative_mcus')->insert([
            'uuid' => 'mcu-1', 'mcu_code' => 'M1', 'name' => 'Union', 'is_active' => true,
            'country_id' => $country, 'region_id' => $region, 'district_id' => $district,
        ]);
        $this->manager = User::factory()->create();
        $this->company = TransporterCompany::create([
            'name' => 'Carrier', 'mcu_uuid' => 'mcu-1', 'license_number' => 'CARRIER-1',
            'contact_person' => 'Manager', 'phone' => '0712345678',
            'manager_user_id' => $this->manager->id, 'approval_status' => 'approved', 'is_active' => true,
        ]);
    }

    private function registration(array $overrides = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'mcu_uuid' => 'mcu-1', 'name' => 'Milk Movers',
            'license_number' => 'REG-77', 'tin_number' => '123-456-789', 'phone' => '0754000000',
            'contact_person' => 'Asha', 'contact_title' => 'Director', 'physical_address' => 'Mbeya',
            ...$overrides,
        ];
    }

    private function driver(array $overrides = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'first_name' => 'Juma', 'last_name' => 'Ali',
            'phone' => '0766111222', 'licence_number' => '1234567890', 'licence_class' => 'C',
            'licence_expiry' => now()->addYears(2)->toDateString(), ...$overrides,
        ];
    }

    public function test_registration_lists_mcus_and_can_be_sent_again_after_rejection(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v2/dairy/transporter-registration/mine')
            ->assertOk()
            ->assertJsonFragment(['uuid' => 'mcu-1', 'name' => 'Union', 'all_regions' => false, 'region_name' => 'Mbeya'])
            ->assertJsonPath('data.company', null);
        $snapshot = app(LoginMembershipSnapshot::class)->forUser($user);
        $this->assertContains('mcu-1', array_column(array_map(fn ($m) => (array) $m, $snapshot['transportation']['mcus']), 'uuid'));

        $this->postJson('/api/v2/dairy/transporter-registration', $this->registration(['mcu_uuid' => 'missing']))
            ->assertUnprocessable();
        foreach ([null, '', '12345'] as $tin) {
            $this->postJson('/api/v2/dairy/transporter-registration', $this->registration(['tin_number' => $tin]))
                ->assertUnprocessable()->assertJsonValidationErrors('tin_number');
        }
        $data = $this->registration();
        $this->postJson('/api/v2/dairy/transporter-registration', [...$data, 'region_id' => 999])
            ->assertCreated()
            ->assertJsonPath('data.region_id', DB::table('regions')->where('name', 'Mbeya')->value('id'))
            ->assertJsonPath('data.district_id', null)
            ->assertJsonPath('data.mcu_uuid', 'mcu-1')
            ->assertJsonPath('data.approval_status', 'pending');
        $this->getJson('/api/v2/dairy/transporter-registration/mine')
            ->assertJsonPath('data.company.mcu_name', 'Union')
            ->assertJsonPath('data.company.contact_title', 'Director');

        DB::table('transporter_companies')->where('uuid', $data['uuid'])
            ->update(['approval_status' => 'rejected', 'approval_remarks' => 'Bad TIN', 'rejected_at' => now()]);
        $this->postJson('/api/v2/dairy/transporter-registration', $this->registration(['tin_number' => '999-999-999']))
            ->assertOk()
            ->assertJsonPath('data.uuid', $data['uuid'])
            ->assertJsonPath('data.approval_status', 'pending')
            ->assertJsonPath('data.approval_remarks', null);
        $this->assertDatabaseCount('transporter_companies', 2);
    }

    public function test_an_all_regions_union_lets_the_transporter_choose_one_of_its_regions(): void
    {
        $country = DB::table('countries')->where('shortName', 'TZ')->value('id');
        $iringa = DB::table('regions')->insertGetId(['name' => 'Iringa', 'shortName' => 'IR', 'countryId' => $country]);
        $abroad = DB::table('regions')->insertGetId([
            'name' => 'Nakuru', 'shortName' => 'NK', 'countryId' => DB::table('countries')->insertGetId(['name' => 'Kenya', 'shortName' => 'KE']),
        ]);
        DB::table('cooperative_mcus')->insert([
            'uuid' => 'mcu-all', 'mcu_code' => 'MALL', 'name' => 'National Union', 'is_active' => true,
            'country_id' => $country, 'all_regions' => true,
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $mcus = collect(app(LoginMembershipSnapshot::class)->forUser($user)['transportation']['mcus'])->keyBy('uuid');
        $this->assertTrue($mcus['mcu-all']['all_regions']);
        $this->assertSame(['Iringa', 'Mbeya'], array_column($mcus['mcu-all']['regions'], 'name'));
        $this->assertSame([], $mcus['mcu-1']['regions']);

        $url = '/api/v2/dairy/transporter-registration';
        $this->postJson($url, $this->registration(['mcu_uuid' => 'mcu-all']))->assertUnprocessable()->assertJsonValidationErrors('region_id');
        $this->postJson($url, $this->registration(['mcu_uuid' => 'mcu-all', 'region_id' => $abroad]))->assertUnprocessable();
        $this->postJson($url, $this->registration(['mcu_uuid' => 'mcu-all', 'region_id' => $iringa]))
            ->assertCreated()->assertJsonPath('data.region_id', $iringa)->assertJsonPath('data.district_id', null);
    }

    public function test_fleet_needs_an_approved_company(): void
    {
        DB::table('transporter_companies')->where('id', $this->company->id)->update(['approval_status' => 'pending']);
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v2/dairy/transporter/fleet')->assertForbidden();
        $this->postJson('/api/v2/dairy/transporter/drivers', $this->driver())->assertForbidden();
    }

    public function test_a_driver_gets_an_account_waiting_for_a_pin_and_a_used_phone_is_refused(): void
    {
        Sanctum::actingAs($this->manager);
        $data = $this->driver(['middle_name' => 'Hassan']);
        $this->postJson('/api/v2/dairy/transporter/drivers', $data)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Juma Hassan Ali')
            ->assertJsonPath('data.middle_name', 'Hassan')
            ->assertJsonPath('data.phone', '+255766111222');
        $this->postJson('/api/v2/dairy/transporter/drivers', $data)->assertOk();
        $this->assertDatabaseHas('phone_identities', [
            'phone' => '+255766111222', 'display_name' => 'Juma Hassan Ali', 'pin_pending' => true,
        ]);
        $this->assertDatabaseCount('transporter_drivers', 1);

        $this->withHeader('Accept-Language', 'sw')
            ->postJson('/api/v2/dairy/transporter/drivers', $this->driver(['first_name' => 'Other']))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Namba 0766111222 tayari inatumiwa na akaunti nyingine. Tumia namba nyingine.');

        $phone = '+255766111222';
        DB::table('phone_auth_challenges')->insert([
            'phone' => $phone, 'purpose' => 'register',
            'code_hash' => app(PhoneAuthentication::class)->hashCode($phone, '123456'),
            'attempts' => 0, 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/auth/phone/check', ['phone' => '0766111222'])->assertJsonPath('next', 'setup');
        $this->postJson('/api/auth/phone/verify-otp', ['phone' => $phone, 'otp' => '123456'])
            ->assertOk()
            ->assertJsonPath('displayName', 'Juma Hassan Ali');

        $driverUser = DB::table('transporter_drivers')->value('user_id');
        Sanctum::actingAs(User::find($driverUser));
        $this->getJson('/api/v2/dairy/memberships/transportation')
            ->assertJsonCount(1, 'data.drivers')
            ->assertJsonPath('data.drivers.0.approval_status', 'approved');
    }

    public function test_vehicles_are_saved_once_and_drivers_assigned_and_unassigned(): void
    {
        Sanctum::actingAs($this->manager);
        $driver = $this->driver();
        $this->postJson('/api/v2/dairy/transporter/drivers', $driver)->assertCreated();
        $vehicle = [
            'uuid' => (string) Str::uuid(), 'registration_number' => 't 123 abc', 'vehicle_type' => 'TANKER',
            'color' => 'White', 'tare_weight_kg' => 4500, 'tanker_capacity_liters' => 8000,
            'driver_uuid' => $driver['uuid'],
        ];
        $this->postJson('/api/v2/dairy/transporter/vehicles', $vehicle)
            ->assertCreated()->assertJsonPath('data.registration_number', 'T123ABC');
        $this->postJson('/api/v2/dairy/transporter/vehicles', $vehicle)->assertOk();
        $this->postJson('/api/v2/dairy/transporter/vehicles', [...$vehicle, 'uuid' => (string) Str::uuid()])
            ->assertStatus(409);

        $this->getJson('/api/v2/dairy/transporter/fleet')
            ->assertJsonPath('data.vehicles.0.assigned_driver_name', 'Juma Ali')
            ->assertJsonPath('data.drivers.0.assigned_vehicle_plate', 'T123ABC')
            ->assertJsonPath('data.drivers.0.status', 'active');

        $this->postJson("/api/v2/dairy/transporter/drivers/{$driver['uuid']}/assign", ['vehicle_uuid' => null])
            ->assertOk()
            ->assertJsonPath('data.assigned_vehicle_plate', null)
            ->assertJsonPath('data.status', 'standby');
    }

    public function test_the_owner_edits_and_deactivates_vehicles_and_drivers(): void
    {
        Sanctum::actingAs($this->manager);
        $driver = $this->driver();
        $this->postJson('/api/v2/dairy/transporter/drivers', $driver)->assertCreated();
        $vehicle = [
            'uuid' => (string) Str::uuid(), 'registration_number' => 'T123ABC', 'tanker_capacity_liters' => 8000,
            'driver_uuid' => $driver['uuid'],
        ];
        $this->postJson('/api/v2/dairy/transporter/vehicles', $vehicle)->assertCreated();
        $edit = ['uuid' => (string) Str::uuid()];

        $this->postJson("/api/v2/dairy/transporter/vehicles/{$vehicle['uuid']}/edit", [...$edit, 'color' => 'blue', 'tanker_capacity_liters' => 9000])
            ->assertOk()->assertJsonPath('data.color', 'blue');
        $this->postJson("/api/v2/dairy/transporter/drivers/{$driver['uuid']}/edit", [...$edit, 'middle_name' => 'Hassan', 'licence_class' => 'D'])
            ->assertOk()
            ->assertJsonPath('data.middle_name', 'Hassan')
            ->assertJsonPath('data.licence_class', 'D');

        $this->postJson("/api/v2/dairy/transporter/vehicles/{$vehicle['uuid']}/status", [...$edit, 'is_active' => false])->assertOk();
        $this->getJson('/api/v2/dairy/transporter/fleet')
            ->assertJsonCount(1, 'data.vehicles')
            ->assertJsonPath('data.vehicles.0.is_active', fn ($active) => ! $active)
            ->assertJsonPath('data.drivers.0.assigned_vehicle_uuid', null);
        $this->postJson("/api/v2/dairy/transporter/drivers/{$driver['uuid']}/assign", ['vehicle_uuid' => $vehicle['uuid']])
            ->assertStatus(422);

        $this->postJson("/api/v2/dairy/transporter/vehicles/{$vehicle['uuid']}/status", [...$edit, 'is_active' => true])->assertOk();
        $this->postJson("/api/v2/dairy/transporter/drivers/{$driver['uuid']}/status", [...$edit, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', fn ($active) => ! $active);
        $this->postJson("/api/v2/dairy/transporter/drivers/{$driver['uuid']}/status", [...$edit, 'is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', fn ($active) => (bool) $active);
        $this->postJson("/api/v2/dairy/transporter/drivers/{$driver['uuid']}/assign", ['vehicle_uuid' => $vehicle['uuid']])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_only_the_owner_decides_a_trip_and_a_repeat_is_accepted(): void
    {
        DB::table('dairy_trips')->insert([
            'uuid' => 'trip-1', 'trip_number' => 'TRIP-1', 'transporter_company_uuid' => $this->company->uuid,
            'driver_user_id' => User::factory()->create()->id, 'vehicle_uuid' => (string) Str::uuid(),
            'vehicle_plate_number' => 'T1', 'destination_factory_uuid' => (string) Str::uuid(),
            'status' => 'pending_approval', 'approval_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v2/dairy/transporter/trips/trip-1/review', ['decision' => 'approved'])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->postJson('/api/v2/dairy/transporter/trips/trip-1/review', ['decision' => 'rejected'])->assertUnprocessable();
        $this->postJson('/api/v2/dairy/transporter/trips/trip-1/review', ['decision' => 'approved'])->assertOk();
        $this->postJson('/api/v2/dairy/transporter/trips/trip-1/review', ['decision' => 'approved'])->assertOk();
        $this->postJson('/api/v2/dairy/transporter/trips/trip-1/review', ['decision' => 'rejected', 'remarks' => 'No'])
            ->assertStatus(409);
        $this->getJson('/api/v2/dairy/transporter/trips')
            ->assertJsonPath('data.0.approval_status', 'approved');
    }
}
