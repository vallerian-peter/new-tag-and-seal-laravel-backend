<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CooperativeRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** A union covering every region of a country. */
    private function nationwide(): array
    {
        $country = DB::table('countries')->where('shortName', 'NW')->value('id')
            ?? DB::table('countries')->insertGetId(['name' => 'Nationwide', 'shortName' => 'NW']);

        return ['country_id' => $country, 'all_regions' => true];
    }

    public function test_super_admin_registers_union_and_linked_and_independent_cooperatives(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $union = (string) Str::uuid();
        $independent = (string) Str::uuid();
        $linked = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/mcus', [
            'uuid' => $union, 'name' => '  Example Union  ', 'code' => '  TEST-MCU  ', ...$this->nationwide(),
        ])->assertCreated();
        $this->postJson('/api/v1/admin/dairy/cooperatives/amcos', [
            'uuid' => $independent, 'name' => 'Independent Test', 'code' => 'TEST-IND',
        ])->assertCreated();
        $this->postJson('/api/v1/admin/dairy/cooperatives/amcos', [
            'uuid' => $linked, 'name' => 'Linked Test', 'code' => 'TEST-LINK', 'mcu_uuid' => $union,
        ])->assertCreated();
        $this->assertDatabaseHas('cooperative_mcus', ['uuid' => $union, 'name' => 'Example Union', 'mcu_code' => 'TEST-MCU']);
        $this->assertDatabaseHas('cooperative_amcos', ['uuid' => $independent, 'mcu_uuid' => null]);
        $this->assertDatabaseHas('cooperative_amcos', ['uuid' => $linked, 'mcu_uuid' => $union]);
        $this->getJson('/api/v1/admin/dairy/cooperatives/amcos?search=Linked%20Test&per_page=10')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mcu_name', 'Example Union')
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/dairy/cooperatives/mcus?search=Example%20Union&status=inactive')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/dairy/cooperatives/options')->assertOk()
            ->assertJsonFragment(['uuid' => $union, 'name' => 'Example Union']);
    }

    public function test_union_and_cooperative_store_registration_tax_website_location_and_contact_person(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Kilimanjaro', 'shortName' => 'KLM', 'countryId' => $country]);
        $otherRegion = DB::table('regions')->insertGetId(['name' => 'Arusha', 'shortName' => 'ARS', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Hai', 'regionId' => $region]);
        $ward = DB::table('wards')->insertGetId(['name' => 'Machame', 'districtId' => $district]);
        $village = DB::table('villages')->insertGetId(['name' => 'Nkuu', 'wardId' => $ward]);
        $details = [
            'registration_number' => ' cop-ars-mru-dc-2021-0458 ', 'tin_number' => '105482913', 'website' => 'example.co.tz',
            'region_id' => $region, 'district_id' => $district, 'ward_id' => $ward, 'village_id' => $village,
            'contact_name' => 'Asha Juma', 'contact_title' => 'Chairperson', 'contact_email' => 'Office@Example.co.tz', 'contact_phone' => '0754000111',
        ];

        foreach (['mcus' => 'cooperative_mcus', 'amcos' => 'cooperative_amcos'] as $type => $table) {
            $uuid = (string) Str::uuid();
            $this->postJson("/api/v1/admin/dairy/cooperatives/{$type}", ['uuid' => $uuid, 'name' => "Kilimanjaro {$type}", ...$details, ...($type === 'mcus' ? ['country_id' => $country] : [])])
                ->assertCreated();
            $this->assertDatabaseHas($table, [
                'uuid' => $uuid, 'registration_number' => 'COP-ARS-MRU-DC-2021-0458', 'tin_number' => '105-482-913',
                'website' => 'https://example.co.tz', 'ward_id' => $type === 'mcus' ? null : $ward, 'village_id' => $type === 'mcus' ? null : $village,
                'contact_title' => 'Chairperson', 'contact_email' => 'office@example.co.tz',
            ]);
            $this->getJson("/api/v1/admin/dairy/cooperatives/{$type}?search=105-482")
                ->assertOk()->assertJsonPath('data.0.region_name', 'Kilimanjaro')->assertJsonPath('data.0.district_name', $type === 'mcus' ? null : 'Hai');

            $this->patchJson("/api/v1/admin/dairy/cooperatives/{$type}/{$uuid}", ['tin_number' => '', 'website' => null, 'contact_title' => 'Secretary'])
                ->assertOk()->assertJsonPath('data.tin_number', null)->assertJsonPath('data.contact_title', 'Secretary');
            $changed = $this->patchJson("/api/v1/admin/dairy/cooperatives/{$type}/{$uuid}", ['region_id' => $otherRegion]);
            if ($type === 'mcus') $changed->assertOk()->assertJsonPath('data.region_id', $otherRegion)->assertJsonPath('data.district_id', null);
            else $changed->assertUnprocessable()->assertJsonValidationErrors('district_id');
        }

        $bad = fn (array $override) => $this->postJson('/api/v1/admin/dairy/cooperatives/amcos', ['uuid' => (string) Str::uuid(), 'name' => 'Bad', ...$override]);
        $bad(['registration_number' => '2023-3677'])->assertUnprocessable()->assertJsonValidationErrors('registration_number');
        $bad(['tin_number' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('tin_number');
        $bad(['contact_email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('contact_email');
        $bad(['region_id' => $otherRegion, 'district_id' => $district])->assertUnprocessable()->assertJsonValidationErrors('district_id');

        $this->getJson('/api/v1/admin/dairy/cooperatives/locations?level=districts&parent_id='.$region)
            ->assertOk()->assertExactJson(['status' => true, 'data' => [['value' => (string) $district, 'label' => 'Hai']]]);
        $this->getJson('/api/v1/admin/dairy/cooperatives/locations?level=districts&parent_id='.$otherRegion)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'admin']));
        $this->getJson('/api/v1/admin/dairy/cooperatives/locations?level=regions')->assertForbidden();
    }

    public function test_location_edits_preserve_unchanged_legacy_registration_numbers(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Arusha', 'shortName' => 'ARS', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Meru', 'regionId' => $region]);

        foreach (['mcus' => 'mcu_code', 'amcos' => 'amcos_code'] as $type => $code) {
            foreach (['MCU/REG/2026/001', 'legacy-registration'] as $registration) {
                $uuid = (string) Str::uuid();
                $table = 'cooperative_'.$type;
                DB::table($table)->insert([
                    'uuid' => $uuid, $code => $uuid, 'name' => 'Existing organisation',
                    'registration_number' => $registration,
                ]);
                $url = "/api/v1/admin/dairy/cooperatives/{$type}/{$uuid}";
                $location = ['region_id' => $region, 'district_id' => $district,
                    ...($type === 'mcus' ? ['country_id' => $country, 'all_regions' => false] : [])];

                // Older clients submit all fields, even when only the location changes.
                $this->patchJson($url, [...$location, 'registration_number' => $registration])
                    ->assertOk()->assertJsonPath('data.registration_number', $registration)
                    ->assertJsonPath('data.region_id', $region)->assertJsonPath('data.district_id', $type === 'mcus' ? null : $district);
                // The edit form now omits an unchanged registration number altogether.
                $this->patchJson($url, [...$location, 'district_id' => null])
                    ->assertOk()->assertJsonPath('data.registration_number', $registration)
                    ->assertJsonPath('data.district_id', null);
                $this->patchJson($url, ['registration_number' => 'invalid replacement'])
                    ->assertUnprocessable()->assertJsonValidationErrors('registration_number');
                $this->assertDatabaseHas($table, ['uuid' => $uuid, 'registration_number' => $registration]);
                $this->patchJson($url, ['registration_number' => ' cop-ars-mru-dc-2021-0458 '])
                    ->assertOk()->assertJsonPath('data.registration_number', 'COP-ARS-MRU-DC-2021-0458');
                $this->patchJson($url, ['registration_number' => null])
                    ->assertOk()->assertJsonPath('data.registration_number', null);
            }
        }
    }

    public function test_registry_rejects_invalid_or_duplicate_records_and_non_super_admins(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/mcus', ['uuid' => $uuid, 'name' => 'Union', 'code' => 'DUP', ...$this->nationwide()])->assertCreated();
        $this->postJson('/api/v1/admin/dairy/cooperatives/mcus', ['uuid' => (string) Str::uuid(), 'name' => 'Union', 'code' => ' DUP ', ...$this->nationwide()])->assertUnprocessable();
        $this->postJson('/api/v1/admin/dairy/cooperatives/mcus', ['uuid' => (string) Str::uuid(), 'name' => ' ', 'code' => 'NEW'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/dairy/cooperatives/amcos', [
            'uuid' => (string) Str::uuid(), 'name' => 'Bad union', 'code' => 'BAD', 'mcu_uuid' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'admin']));
        $this->getJson('/api/v1/admin/dairy/cooperatives/mcus')->assertForbidden();
        $this->postJson('/api/v1/admin/dairy/cooperatives/mcus', ['uuid' => (string) Str::uuid(), 'name' => 'Denied', 'code' => 'DENY'])->assertForbidden();
    }

    public function test_legacy_organization_creation_does_not_assign_first_union(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        DB::table('cooperative_mcus')->insert(['uuid' => (string) Str::uuid(), 'name' => 'Existing', 'mcu_code' => 'EXIST']);
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v2/dairy/organizations', [
            'uuid' => $uuid, 'org_type' => 'amcos', 'name' => 'Independent', 'code' => 'LEGACY-IND',
        ])->assertCreated();
        $this->assertDatabaseHas('cooperative_amcos', ['uuid' => $uuid, 'mcu_uuid' => null]);
    }

    public function test_pending_staff_list_uses_verified_phone_identity(): void
    {
        $admin = User::factory()->create();
        $applicant = User::factory()->create();
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Staff Test', 'amcos_code' => 'STAFF']);
        DB::table('phone_identities')->insert(['user_id' => $applicant->id, 'phone' => '+255712345678', 'pin_hash' => 'hash']);
        DB::table('cooperative_user_memberships')->insert([
            ['uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'amcos_uuid' => $amcos, 'role' => 'amcos_admin', 'status' => 'approved'],
            ['uuid' => (string) Str::uuid(), 'user_id' => $applicant->id, 'amcos_uuid' => $amcos, 'role' => 'centre_clerk', 'status' => 'pending'],
        ]);
        $this->actingAs($admin)->getJson('/api/v2/dairy/amcos-membership/pending?amcos_uuid='.$amcos)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.phone', '+255712345678');
    }

    public function test_registry_generates_internal_codes_and_edits_archives_and_restores_without_deleting_history(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/amcos', ['uuid' => $uuid, 'name' => 'Original'])
            ->assertCreated()->assertJsonPath('data.amcos_code', 'AMCOS-'.$uuid);
        $centre = (string) Str::uuid();
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $centre, 'amcos_uuid' => $uuid, 'centre_code' => 'TEST-CENTRE', 'name' => 'Centre',
        ]);
        $this->patchJson('/api/v1/admin/dairy/cooperatives/amcos/'.$uuid, [
            'name' => ' Updated ', 'contact_name' => 'Manager',
        ])->assertOk()->assertJsonPath('data.name', 'Updated');
        $this->patchJson('/api/v1/admin/dairy/cooperatives/amcos/'.$uuid, ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', 0);
        $this->assertDatabaseHas('cooperative_collection_centres', ['uuid' => $centre, 'amcos_uuid' => $uuid]);
        $this->patchJson('/api/v1/admin/dairy/cooperatives/amcos/'.$uuid, ['is_active' => true])->assertOk();
        $this->assertDatabaseHas('cooperative_amcos', ['uuid' => $uuid, 'is_active' => 1]);
        $this->patchJson('/api/v1/admin/dairy/cooperatives/amcos/'.Str::uuid(), ['is_active' => false])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'admin']));
        $this->patchJson('/api/v1/admin/dairy/cooperatives/amcos/'.$uuid, ['name' => 'Denied'])->assertForbidden();
    }

    public function test_centres_list_uses_cooperative_centres_and_searches_cooperative_names(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Distinct Cooperative', 'amcos_code' => 'DISTINCT']);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $amcos, 'centre_code' => 'CC-UNIQUE', 'name' => 'Centre Name',
        ]);
        $this->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Distinct%20Cooperative&per_page=10')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Centre Name')
            ->assertJsonPath('data.0.amcos_name', 'Distinct Cooperative')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Centre%20Name&status=inactive')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_active_and_archived_registry_lists_follow_archive_and_restore(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));

        foreach (['mcus', 'amcos'] as $type) {
            $uuid = (string) Str::uuid();
            $name = 'Tab Test '.$type;
            $url = '/api/v1/admin/dairy/cooperatives/'.$type;
            $query = '?search='.urlencode($name);
            $this->postJson($url, ['uuid' => $uuid, 'name' => $name, ...($type === 'mcus' ? $this->nationwide() : [])])->assertCreated();
            $this->getJson($url.$query.'&status=active')->assertOk()->assertJsonCount(1, 'data');
            $this->getJson($url.$query.'&status=inactive')->assertOk()->assertJsonCount(0, 'data');

            $this->patchJson($url.'/'.$uuid, ['is_active' => false])->assertOk();
            $this->getJson($url.$query.'&status=active')->assertOk()->assertJsonCount(0, 'data');
            $archived = $this->getJson($url.$query.'&status=inactive')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $uuid);
            $stats = $archived->json('meta.stats');
            $this->assertGreaterThanOrEqual(1, $stats['total'] - $stats['active']);

            $this->patchJson($url.'/'.$uuid, ['is_active' => true])->assertOk();
            $this->getJson($url.$query.'&status=active')->assertOk()->assertJsonCount(1, 'data');
            $this->getJson($url.$query.'&status=inactive')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_centre_edit_archive_and_restore_preserve_stock_and_are_super_admin_only(): void
    {
        $super = User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']);
        $admin = User::factory()->create(['role' => 'systemUser', 'platform_role' => 'admin']);
        foreach (['dairy.view', 'dairy.manage'] as $permission) {
            DB::table('user_platform_permissions')->insert([
                'user_uuid' => $admin->uuid,
                'permission_id' => DB::table('platform_permissions')->where('code', $permission)->value('id'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $amcos = (string) Str::uuid();
        $centre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Centre Cooperative', 'amcos_code' => 'CENTRE-A']);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $centre, 'amcos_uuid' => $amcos, 'centre_code' => 'CENTRE-C', 'name' => 'Centre Original',
            'default_unit' => 'kg', 'current_balance' => 12, 'contact_name' => 'Old Contact',
        ]);
        $url = '/api/v1/admin/dairy/cooperatives/centres/'.$centre;
        $this->actingAs($admin)->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Centre%20Original')
            ->assertOk()->assertJsonPath('meta.canManage', false);
        $this->patchJson($url, ['is_active' => false])->assertForbidden();

        $this->actingAs($super);
        $this->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Centre%20Original')
            ->assertOk()->assertJsonPath('meta.canManage', true);
        $this->patchJson($url, ['name' => ' ', 'contact_name' => null])->assertUnprocessable();
        $this->patchJson($url, ['name' => ' Centre Updated ', 'contact_name' => null, 'contact_phone' => '123'])
            ->assertOk()->assertJsonPath('data.name', 'Centre Updated')->assertJsonPath('data.contact_name', null);
        $this->patchJson($url, ['is_active' => false])->assertOk();
        $this->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Centre%20Updated&status=active')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Centre%20Updated&status=inactive')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $centre);
        $this->assertDatabaseHas('cooperative_collection_centres', [
            'uuid' => $centre, 'amcos_uuid' => $amcos, 'default_unit' => 'kg',
            'current_balance' => 12, 'is_active' => 0,
        ]);
        $this->patchJson($url, ['is_active' => true])->assertOk();
        $this->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Centre%20Updated&status=active')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/admin/dairy/cooperatives/centres/'.Str::uuid(), ['is_active' => false])->assertNotFound();
        $this->postJson('/api/v1/admin/dairy/cooperatives/centres', ['uuid' => (string) Str::uuid(), 'name' => 'No cooperative'])
            ->assertUnprocessable();
    }

    public function test_super_admin_registers_a_collection_centre_under_a_cooperative(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Kilimanjaro', 'shortName' => 'KLM', 'countryId' => $country]);
        $otherRegion = DB::table('regions')->insertGetId(['name' => 'Arusha', 'shortName' => 'ARS', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Hai', 'regionId' => $region]);
        $union = (string) Str::uuid();
        $amcos = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/mcus', ['uuid' => $union, 'name' => 'Example Union', 'country_id' => $country, 'region_id' => $region])->assertCreated();
        $this->postJson('/api/v1/admin/dairy/cooperatives/amcos', ['uuid' => $amcos, 'name' => 'Example AMCOS', 'mcu_uuid' => $union])->assertCreated();

        $centre = (string) Str::uuid();
        $this->postJson('/api/v1/admin/dairy/cooperatives/centres', [
            'uuid' => $centre, 'name' => '  Machame Centre  ', 'amcos_uuid' => $amcos,
            'region_id' => $region, 'district_id' => $district,
        ])->assertCreated()->assertJsonPath('data.mcu_uuid', $union);

        $this->assertDatabaseHas('cooperative_collection_centres', ['uuid' => $centre, 'name' => 'Machame Centre', 'amcos_uuid' => $amcos, 'is_active' => true]);
        $this->assertDatabaseHas('cooperative_centre_stocks', ['centre_uuid' => $centre, 'commodity_code' => 'milk']);
        $this->getJson('/api/v1/admin/dairy/cooperatives/centres?search=Machame')
            ->assertOk()->assertJsonPath('data.0.amcos_name', 'Example AMCOS');

        $this->postJson('/api/v1/admin/dairy/cooperatives/centres', [
            'uuid' => (string) Str::uuid(), 'name' => 'Wrong place', 'amcos_uuid' => $amcos,
            'region_id' => $otherRegion, 'district_id' => $district,
        ])->assertStatus(422)->assertJsonValidationErrors('district_id');
        $this->postJson('/api/v1/admin/dairy/cooperatives/centres', ['uuid' => (string) Str::uuid(), 'name' => 'No cooperative'])
            ->assertStatus(422)->assertJsonValidationErrors(['amcos_uuid', 'region_id', 'district_id']);

        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'admin']));
        $this->postJson('/api/v1/admin/dairy/cooperatives/centres', [
            'uuid' => (string) Str::uuid(), 'name' => 'Not allowed', 'amcos_uuid' => $amcos,
            'region_id' => $region, 'district_id' => $district,
        ])->assertForbidden();
    }

    public function test_union_needs_a_country_and_covers_one_region_or_all_regions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']));
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $abroad = DB::table('countries')->insertGetId(['name' => 'Kenya', 'shortName' => 'KE']);
        $region = DB::table('regions')->insertGetId(['name' => 'Kilimanjaro', 'shortName' => 'KLM', 'countryId' => $country]);
        $foreign = DB::table('regions')->insertGetId(['name' => 'Nakuru', 'shortName' => 'NKR', 'countryId' => $abroad]);
        $district = DB::table('districts')->insertGetId(['name' => 'Hai', 'regionId' => $region]);
        $url = '/api/v1/admin/dairy/cooperatives/mcus';
        $post = fn (array $body) => $this->postJson($url, ['uuid' => (string) Str::uuid(), 'name' => 'Union', ...$body]);

        $post(['region_id' => $region])->assertUnprocessable()->assertJsonValidationErrors('country_id');
        $post(['country_id' => $country])->assertUnprocessable()->assertJsonValidationErrors('region_id');
        $post(['country_id' => $country, 'region_id' => $foreign])->assertUnprocessable()->assertJsonValidationErrors('region_id');

        $national = (string) Str::uuid();
        $this->postJson($url, ['uuid' => $national, 'name' => 'National', 'country_id' => $country, 'all_regions' => true, 'region_id' => $region, 'district_id' => $district])
            ->assertCreated();
        $this->assertDatabaseHas('cooperative_mcus', ['uuid' => $national, 'country_id' => $country, 'all_regions' => true, 'region_id' => null, 'district_id' => null]);
        $this->getJson($url.'?search=National')->assertOk()->assertJsonPath('data.0.country_name', 'Tanzania');

        $this->patchJson("{$url}/{$national}", ['all_regions' => false, 'region_id' => $region, 'district_id' => $district])
            ->assertOk()->assertJsonPath('data.region_id', $region)->assertJsonPath('data.all_regions', 0);
        $this->patchJson("{$url}/{$national}", ['all_regions' => true])
            ->assertOk()->assertJsonPath('data.region_id', null)->assertJsonPath('data.district_id', null);
        $this->getJson('/api/v1/admin/dairy/cooperatives/locations?level=countries')
            ->assertOk()->assertJsonFragment(['value' => (string) $country, 'label' => 'Tanzania']);
    }
}
