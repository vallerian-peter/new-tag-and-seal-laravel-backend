<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionCentreRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private string $amcos;
    private array $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        $this->amcos = (string) Str::uuid();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Arusha', 'shortName' => 'AR', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Meru', 'regionId' => $region]);
        $ward = DB::table('wards')->insertGetId(['name' => 'Usa River', 'districtId' => $district]);
        $otherWard = DB::table('wards')->insertGetId(['name' => 'Other', 'districtId' => $district]);
        $village = DB::table('villages')->insertGetId(['name' => 'Maji ya Chai', 'wardId' => $ward]);
        $this->place = compact('region', 'district', 'ward', 'otherWard', 'village');
        DB::table('cooperative_amcos')->insert(['uuid' => $this->amcos, 'amcos_code' => 'A1', 'name' => 'Cooperative']);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->staff->id,
            'amcos_uuid' => $this->amcos, 'role' => 'amcos_user', 'status' => 'approved',
        ]);
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->staff)->postJson('/api/v2/dairy/centres', array_merge([
            'uuid' => (string) Str::uuid(),
            'amcos_uuid' => $this->amcos,
            'name' => 'Usa River Centre',
            'contact_name' => 'Neema',
            'contact_phone' => '0712000111',
            'region_id' => $this->place['region'],
            'district_id' => $this->place['district'],
            'ward_id' => $this->place['ward'],
            'village_id' => $this->place['village'],
        ], $overrides));
    }

    public function test_centre_saves_location_and_is_listed_with_commodities(): void
    {
        $uuid = (string) Str::uuid();
        $this->save(['uuid' => $uuid])->assertCreated();

        $centre = collect($this->actingAs($this->staff)->getJson('/api/v2/dairy/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.commodities.0.code', 'milk')
            ->assertJsonCount(1, 'data.commodities')
            ->json('data.centres'))->firstWhere('uuid', $uuid);

        $this->assertArrayNotHasKey('commodity_codes', $centre);
        $this->assertSame('l', $centre['default_unit']);
        $this->assertSame('Usa River', $centre['ward_name']);
        $this->assertSame('Maji ya Chai', $centre['village_name']);
        $this->assertSame('Neema', $centre['contact_name']);
    }

    public function test_village_outside_the_ward_is_rejected(): void
    {
        $this->save(['ward_id' => $this->place['otherWard']])->assertStatus(422)->assertJsonValidationErrors('villageId');
    }

    public function test_edit_clears_contact_and_keeps_opening_balance(): void
    {
        $uuid = (string) Str::uuid();
        $this->save(['uuid' => $uuid, 'opening_balance' => 40])->assertCreated();

        $this->save(['uuid' => $uuid, 'name' => 'Renamed', 'contact_name' => null, 'opening_balance' => 99])->assertOk();

        $centre = DB::table('cooperative_collection_centres')->where('uuid', $uuid)->first();
        $this->assertSame('Renamed', $centre->name);
        $this->assertNull($centre->contact_name);
        $this->assertEquals(40, $centre->current_balance);
    }

    public function test_reuploading_the_same_uuid_upserts_one_centre(): void
    {
        $uuid = (string) Str::uuid();
        $this->save(['uuid' => $uuid])->assertCreated();
        $this->save(['uuid' => $uuid])->assertOk();
        $this->save(['uuid' => $uuid, 'name' => 'Usa River Upper'])->assertOk()
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.name', 'Usa River Upper');

        $this->assertSame(1, DB::table('cooperative_collection_centres')->where('uuid', $uuid)->count());
        $this->assertSame(1, DB::table('cooperative_centre_stocks')->where('centre_uuid', $uuid)->count());
    }
}
