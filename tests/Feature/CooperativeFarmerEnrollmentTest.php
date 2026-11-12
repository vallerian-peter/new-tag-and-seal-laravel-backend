<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CooperativeFarmerEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private string $amcos;
    private string $otherAmcos;
    private string $centre;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        $this->amcos = (string) Str::uuid();
        $this->otherAmcos = (string) Str::uuid();
        $this->centre = (string) Str::uuid();
        DB::table('countries')->insert(['name' => 'Tanzania', 'shortName' => 'TZ']);
        DB::table('cooperative_amcos')->insert([
            ['uuid' => $this->amcos, 'name' => 'One', 'amcos_code' => 'ONE'],
            ['uuid' => $this->otherAmcos, 'name' => 'Two', 'amcos_code' => 'TWO'],
        ]);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $this->centre, 'amcos_uuid' => $this->amcos, 'name' => 'Centre', 'centre_code' => 'C1',
        ]);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->staff->id,
            'amcos_uuid' => $this->amcos, 'role' => 'amcos_user', 'status' => 'approved',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'amcos_uuid' => $this->amcos,
            'enrolled_centre_uuids' => [$this->centre],
            'phone' => '0712345678',
            'first_name' => 'Asha',
            'last_name' => 'Juma',
        ], $overrides);
    }

    public function test_unknown_phone_creates_farmer_and_connects_amcos(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload())
            ->assertOk()->assertJsonPath('data.result', 'created')->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('cooperative_farmer_memberships', [
            'amcos_uuid' => $this->amcos, 'farmer_phone' => '0712345678', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('phone_identities', ['phone' => '+255712345678', 'pin_pending' => true]);
        $this->postJson('/api/auth/phone/check', ['phone' => '0712345678'])->assertOk()->assertJsonPath('next', 'setup');
    }

    public function test_new_amcos_farmer_gets_one_kiswahili_registration_sms(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload())->assertOk();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload(['uuid' => (string) Str::uuid()]))
            ->assertOk()->assertJsonPath('data.result', 'connected');

        $sms = DB::table('hiring_notifications')->where('kind', 'amcos_farmer_registered')->get();
        $this->assertCount(1, $sms);
        $this->assertSame('amcos_farmer_registered:'.$response->json('data.membership_uuid'), $sms[0]->event_key);
        $this->assertSame(
            'Tag & Seal: Hongera Asha Juma! Umesajiliwa kama mkulima wa AMCOS One. Namba yako ya uanachama ni '
            .$response->json('data.member_number').' na namba yako ya mkulima ni '.$response->json('data.farmer_no')
            .'. Sasa unaweza kupeleka maziwa kwenye vituo vya ukusanyaji vya AMCOS hii.',
            $sms[0]->message_sw,
        );
    }

    public function test_each_new_farmer_gets_its_own_farmer_number(): void
    {
        $first = $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload())
            ->assertOk()->json('data.farmer_no');
        $second = $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'amcos_uuid' => $this->amcos, 'phone' => '0799999999', 'first_name' => 'Neema', 'last_name' => 'Peter',
        ]))->assertOk()->json('data.farmer_no');

        $this->assertMatchesRegularExpression('/^FMR-\d{4}-\d{6}$/', $first);
        $this->assertMatchesRegularExpression('/^FMR-\d{4}-\d{6}$/', $second);
        $this->assertNotSame($first, $second);

        $this->actingAs($this->staff)->getJson('/api/v2/dairy/farmers?amcos_uuid='.$this->amcos)
            ->assertOk()->assertJsonFragment(['farmer_no' => $first]);
    }

    public function test_same_data_only_connects_the_existing_farmer(): void
    {
        $farmerNo = $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload())
            ->assertOk()->json('data.farmer_no');
        $farmers = DB::table('farmers')->count();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload(['uuid' => (string) Str::uuid()]))
            ->assertOk()->assertJsonPath('data.result', 'connected')->assertJsonPath('data.farmer_no', $farmerNo);

        $this->assertSame($farmers, DB::table('farmers')->count());
        $this->assertSame(1, DB::table('cooperative_farmer_memberships')->count());
    }

    public function test_different_data_for_a_known_phone_is_a_conflict(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload())->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Neema', 'last_name' => 'Peter',
        ]))->assertStatus(409)
            ->assertJsonPath('code', 'conflict')
            ->assertJsonPath('data.reason', 'different_data')
            ->assertJsonPath('data.existing_name', 'Asha Juma')
            ->assertJsonPath('data.differences', ['first name', 'surname']);

        $this->assertSame('Asha', DB::table('farmers')->where('phone1', '0712345678')->value('firstName'));
        $this->assertSame(1, DB::table('cooperative_farmer_memberships')->count());
    }

    public function test_matching_phone_with_a_different_detail_is_not_synced(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'gender' => 'male',
        ]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => (string) Str::uuid(),
            'gender' => 'female',
        ]))->assertStatus(409)
            ->assertJsonPath('data.differences', ['gender'])
            ->assertJsonPath('message', 'This phone is already registered. These details do not match and were not synced: gender.');

        $this->assertSame('male', DB::table('farmers')->where('phone1', '0712345678')->value('gender'));
    }

    public function test_minor_details_do_not_block_matching_a_known_phone(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'identity_number' => '19900101-12345-00001-20',
        ]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => (string) Str::uuid(),
            'first_name' => '  asha ',
            'middle_name' => 'Different',
            'farmer_type' => 'organization',
            'organization_name' => 'Other group',
            'identity_number' => '19900101123450000120',
            'email' => 'asha@example.com',
        ]))->assertOk()->assertJsonPath('data.result', 'connected');

        $farmer = DB::table('farmers')->where('phone1', '0712345678')->first();
        $this->assertSame('Asha', $farmer->firstName);
        $this->assertSame('asha@example.com', $farmer->email);
    }

    public function test_different_identity_number_blocks_matching_a_known_phone(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'identity_number' => '19900101-12345-00001-20',
        ]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => (string) Str::uuid(),
            'identity_number' => '19900101-12345-00001-99',
        ]))->assertStatus(409)->assertJsonPath('data.differences', ['identity number']);
    }

    public function test_email_matches_ignoring_case_and_blocks_when_different(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'email' => 'asha@example.com',
        ]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => (string) Str::uuid(),
            'email' => 'ASHA@example.com',
        ]))->assertOk()->assertJsonPath('data.result', 'connected');

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => (string) Str::uuid(),
            'email' => 'other@example.com',
        ]))->assertStatus(409)->assertJsonPath('data.differences', ['email']);
    }

    public function test_enrollment_does_not_require_a_collection_centre(): void
    {
        $payload = $this->payload();
        unset($payload['enrolled_centre_uuids']);

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $payload)
            ->assertOk()->assertJsonPath('data.result', 'created');

        $this->assertSame('[]', DB::table('cooperative_farmer_memberships')->value('enrolled_centre_uuids'));
    }

    public function test_existing_farmer_otp_returns_the_membership_uuid(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload())->assertOk();

        Http::fake();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/request-otp', [
            'amcos_uuid' => $this->amcos,
            'phone' => '0712345678',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $otp = DB::table('cooperative_farmer_memberships')->value('otp_code');
        Http::assertSent(fn ($request) => str_contains(
            (string) ($request['message'] ?? ''),
            "Tag & Seal: Unaingia kwenye AMCOS One kwa uthibitisho. Namba yako ya OTP ni {$otp}. Nenda ukabidhi namba hii kwa mtumiaji wa AMCOS."
        ));

        $membership = DB::table('cooperative_farmer_memberships')->first();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/verify-otp', [
            'membership_uuid' => $membership->uuid,
            'otp_code' => $membership->otp_code,
        ])->assertOk()->assertJsonPath('data.uuid', $membership->uuid)->assertJsonPath('data.status', 'active');
    }

    public function test_unknown_phone_is_not_created_by_the_otp_lookup(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/request-otp', [
            'amcos_uuid' => $this->amcos,
            'phone' => '0788000111',
        ])->assertStatus(404)
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'This phone is not an existing farmer phone.');

        $this->assertSame(0, DB::table('otp_verifications')->count());
        $this->assertSame(0, DB::table('farmers')->where('phone1', '0788000111')->count());
    }

    public function test_farmer_active_in_another_amcos_is_a_conflict(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload())->assertOk();
        DB::table('cooperative_farmer_memberships')->update(['amcos_uuid' => $this->otherAmcos]);

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload(['uuid' => (string) Str::uuid()]))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'other_amcos')
            ->assertJsonPath('message', 'Asha Juma (0712345678) is already linked to another AMCOS (Two).');
    }

    public function test_delete_inserts_the_farmer_assigns_the_amcos_and_archives(): void
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => $uuid,
            'sync_action' => 'DELETE',
        ]))->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.amcos_uuid', $this->amcos);

        $this->assertDatabaseHas('farmers', ['phone1' => '0712345678']);
        $this->assertDatabaseHas('cooperative_farmer_memberships', [
            'uuid' => $uuid,
            'amcos_uuid' => $this->amcos,
            'status' => 'archived',
        ]);
        $this->assertSame(1, DB::table('cooperative_farmer_memberships')->count());
        $this->assertSame(1, DB::table('farmers')->count());
    }

    public function test_delete_of_an_active_farmer_archives_instead_of_deleting(): void
    {
        $uuid = (string) Str::uuid();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload(['uuid' => $uuid]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => $uuid,
            'sync_action' => 'DELETE',
        ]))->assertOk()->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('cooperative_farmer_memberships', [
            'uuid' => $uuid,
            'amcos_uuid' => $this->amcos,
            'status' => 'archived',
        ]);
        $this->assertSame(1, DB::table('farmers')->count());
        $this->assertSame(1, DB::table('cooperative_farmer_memberships')->count());
    }

    public function test_update_writes_details_for_this_amcos(): void
    {
        $uuid = (string) Str::uuid();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload(['uuid' => $uuid]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => $uuid,
            'sync_action' => 'UPDATE',
            'last_name' => 'Mwenda',
        ]))->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('farmers', ['phone1' => '0712345678', 'surname' => 'Mwenda']);
        $this->assertDatabaseHas('cooperative_farmer_memberships', [
            'uuid' => $uuid,
            'amcos_uuid' => $this->amcos,
            'status' => 'active',
            'farmer_name' => 'Asha Mwenda',
        ]);
    }

    public function test_update_of_an_archived_farmer_is_a_conflict(): void
    {
        $uuid = (string) Str::uuid();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload(['uuid' => $uuid]))->assertOk();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => $uuid, 'sync_action' => 'DELETE',
        ]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => $uuid, 'sync_action' => 'UPDATE', 'last_name' => 'Mwenda',
        ]))->assertStatus(409);

        $this->assertDatabaseHas('farmers', ['phone1' => '0712345678', 'surname' => 'Juma']);
        $this->assertDatabaseHas('cooperative_farmer_memberships', ['uuid' => $uuid, 'status' => 'archived']);
    }

    public function test_update_clears_optional_details_and_keeps_required_ones(): void
    {
        $uuid = (string) Str::uuid();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => $uuid, 'middle_name' => 'Neema', 'identity_number' => 'ID-1',
        ]))->assertOk();

        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'uuid' => $uuid,
            'sync_action' => 'UPDATE',
            'first_name' => null,
            'middle_name' => null,
            'identity_number' => null,
        ]))->assertOk();

        $this->assertDatabaseHas('farmers', [
            'phone1' => '0712345678', 'firstName' => 'Asha', 'middleName' => null, 'identityNumber' => null,
        ]);
    }

    public function test_farmer_list_returns_only_this_amcos_farmers_that_are_not_archived(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'phone' => '0711111111',
        ]))->assertOk();
        $this->actingAs($this->staff)->postJson('/api/v2/dairy/farmers/enroll', $this->payload([
            'phone' => '0722222222',
            'sync_action' => 'DELETE',
        ]))->assertOk();

        $rows = collect($this->actingAs($this->staff)
            ->getJson('/api/v2/dairy/farmers?amcos_uuid='.$this->amcos)
            ->assertOk()
            ->json('data.enrolled_farmers'));

        $this->assertTrue($rows->pluck('farmer_phone')->contains('0711111111'));
        $this->assertFalse($rows->pluck('farmer_phone')->contains('0722222222'));
        $this->assertTrue($rows->every(fn ($row) => $row['amcos_uuid'] === $this->amcos && $row['status'] !== 'archived'));
    }
}
