<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Farmer membership and AMCOS registration store the portal's farmer details. */
class FarmerProfileConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private array $place;

    private int $idType;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Arusha', 'shortName' => 'AR', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Meru', 'regionId' => $region]);
        $ward = DB::table('wards')->insertGetId(['name' => 'Usa River', 'districtId' => $district]);
        $otherWard = DB::table('wards')->insertGetId(['name' => 'Other', 'districtId' => $district]);
        $village = DB::table('villages')->insertGetId(['name' => 'Maji ya Chai', 'wardId' => $ward]);
        $street = DB::table('streets')->insertGetId(['name' => 'Sokoni', 'wardId' => $ward]);
        $this->place = compact('country', 'region', 'district', 'ward', 'otherWard', 'village', 'street');
        $this->idType = DB::table('identity_card_types')->insertGetId(['name' => 'VOTERS ID']);
        $this->school = DB::table('school_levels')->insertGetId(['name' => 'Primary Education']);
    }

    private function assertProfileSaved(string $phone): void
    {
        $farmer = DB::table('farmers')->where('phone1', $phone)->first();
        $this->assertNotNull($farmer);
        $this->assertSame('1990-05-04', substr((string) $farmer->dateOfBirth, 0, 10));
        $this->assertSame($this->idType, (int) $farmer->identityCardTypeId);
        $this->assertSame('T-1234-5678', $farmer->identityNumber);
        $this->assertSame($this->school, (int) $farmer->schoolLevelId);
        $this->assertSame('0755000111', $farmer->phone2);
        $this->assertSame('asha@example.com', $farmer->email);
        $this->assertSame('Plot 4, Usa River', $farmer->physicalAddress);
        $this->assertSame($this->place['ward'], (int) $farmer->wardId);
        $this->assertSame($this->place['village'], (int) $farmer->villageId);
        $this->assertSame((string) $this->place['street'], (string) $farmer->street);
    }

    public function test_farmer_membership_saves_the_portal_fields(): void
    {
        $user = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $details = [
            'name' => 'Asha Juma', 'firstName' => 'Asha', 'surname' => 'Juma', 'phone' => '0712345678',
            'gender' => 'female', 'farmerType' => 'individual', 'dateOfBirth' => '1990-05-04',
            'identityCardTypeId' => $this->idType, 'identityNumber' => 'T-1234-5678', 'schoolLevelId' => $this->school,
            'phone2' => '0755000111', 'email' => 'Asha@Example.com', 'physicalAddress' => 'Plot 4, Usa River',
            'countryId' => $this->place['country'], 'regionId' => $this->place['region'], 'districtId' => $this->place['district'],
            'wardId' => $this->place['ward'], 'villageId' => $this->place['village'], 'streetId' => $this->place['street'],
        ];

        $this->actingAs($user)->postJson('/api/v2/memberships', [
            'feature' => 'farmer', 'details' => [...$details, 'villageId' => null, 'wardId' => $this->place['otherWard']],
        ])->assertUnprocessable()->assertJsonValidationErrors('details.streetId');

        $this->actingAs($user)->postJson('/api/v2/memberships', ['feature' => 'farmer', 'details' => $details])->assertOk();

        $this->assertProfileSaved('0712345678');
    }

    public function test_amcos_registration_saves_and_returns_the_portal_fields(): void
    {
        $clerk = User::factory()->create();
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'amcos_code' => 'A1', 'name' => 'Cooperative']);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $clerk->id, 'amcos_uuid' => $amcos, 'role' => 'amcos_admin', 'status' => 'approved',
        ]);
        $payload = [
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $amcos, 'phone' => '0712345679', 'sync_action' => 'CREATE',
            'first_name' => 'Asha', 'last_name' => 'Juma', 'gender' => 'female', 'farmer_type' => 'individual',
            'date_of_birth' => '1990-05-04', 'identity_card_type_id' => $this->idType, 'identity_number' => 'T-1234-5678',
            'school_level_id' => $this->school, 'phone2' => '0755000111', 'email' => 'asha@example.com',
            'physical_address' => 'Plot 4, Usa River', 'country_id' => $this->place['country'], 'region_id' => $this->place['region'],
            'district_id' => $this->place['district'], 'ward_id' => $this->place['ward'], 'village_id' => $this->place['village'],
            'street_id' => $this->place['street'],
        ];
        $this->actingAs($clerk);

        $this->postJson('/api/v2/dairy/farmers/enroll', [...$payload, 'village_id' => null, 'ward_id' => $this->place['otherWard']])
            ->assertUnprocessable()->assertJsonValidationErrors('streetId');
        $this->postJson('/api/v2/dairy/farmers/enroll', $payload)->assertOk();

        $this->assertProfileSaved('0712345679');
        $this->getJson('/api/v2/dairy/farmers?amcos_uuid='.$amcos)->assertOk()
            ->assertJsonPath('data.enrolled_farmers.0.school_level_id', $this->school)
            ->assertJsonPath('data.enrolled_farmers.0.email', 'asha@example.com')
            ->assertJsonPath('data.enrolled_farmers.0.ward_id', $this->place['ward']);

        $this->postJson('/api/v2/dairy/farmers/enroll', [...$payload, 'sync_action' => 'UPDATE', 'email' => null, 'phone2' => '0755000222'])
            ->assertOk();
        $farmer = DB::table('farmers')->where('phone1', '0712345679')->first();
        $this->assertNull($farmer->email);
        $this->assertSame('0755000222', $farmer->phone2);
    }
}
