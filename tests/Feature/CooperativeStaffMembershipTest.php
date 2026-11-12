<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Services\Dairy\CooperativeMembershipNotifications;
use Tests\TestCase;

class CooperativeStaffMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejection_removes_only_membership_and_allows_reapplication(): void
    {
        $admin = User::factory()->create();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = DB::table('farmers')->insertGetId([
            'farmerNo' => 'FARMER-1', 'firstName' => 'Example', 'surname' => 'Farmer',
            'phone1' => '0799999999', 'gender' => 'female', 'farmerType' => 'individual', 'countryId' => $country,
        ]);
        $applicant = User::factory()->create();
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Test', 'amcos_code' => 'TEST']);
        DB::table('phone_identities')->insert(['user_id' => $applicant->id, 'phone' => '+255712345678', 'pin_hash' => 'hash']);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $admin->id,
            'amcos_uuid' => $amcos, 'role' => 'amcos_admin', 'status' => 'approved',
        ]);

        $application = ['amcos_uuid' => $amcos, 'applicant_name' => 'Example Farmer', 'applicant_gender' => 'female', 'applicant_date_of_birth' => '1990-01-02'];
        $this->actingAs($applicant);
        $first = $this->postJson('/api/v2/dairy/amcos-membership', $application)
            ->assertCreated()->json('data.uuid');
        $this->postJson('/api/v2/dairy/amcos-membership', $application)
            ->assertOk()->assertJsonPath('data.uuid', $first);

        $this->actingAs($admin)->postJson("/api/v2/dairy/amcos-membership/{$first}/review", ['decision' => 'rejected'])
            ->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseMissing('cooperative_user_memberships', ['uuid' => $first]);
        $this->assertDatabaseHas('users', ['id' => $applicant->id]);
        $this->assertDatabaseHas('phone_identities', ['user_id' => $applicant->id]);
        $this->assertDatabaseHas('farmers', ['id' => $farmer]);
        $this->assertDatabaseHas('cooperative_membership_reviews', [
            'membership_uuid' => $first, 'applicant_user_id' => $applicant->id,
            'reviewer_user_id' => $admin->id, 'decision' => 'rejected',
        ]);

        $this->actingAs($applicant);
        $second = $this->postJson('/api/v2/dairy/amcos-membership', $application)
            ->assertCreated()->json('data.uuid');
        $this->assertNotSame($first, $second);
    }

    public function test_review_is_scoped_and_rejects_invalid_centres_and_stale_decisions(): void
    {
        $admin = User::factory()->create();
        $outsider = User::factory()->create();
        $applicant = User::factory()->create();
        $amcos = (string) Str::uuid();
        $otherAmcos = (string) Str::uuid();
        $membership = (string) Str::uuid();
        $centre = (string) Str::uuid();
        $otherCentre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert([
            ['uuid' => $amcos, 'name' => 'One', 'amcos_code' => 'ONE'],
            ['uuid' => $otherAmcos, 'name' => 'Two', 'amcos_code' => 'TWO'],
        ]);
        DB::table('cooperative_collection_centres')->insert([
            ['uuid' => $centre, 'amcos_uuid' => $amcos, 'name' => 'One', 'centre_code' => 'ONE'],
            ['uuid' => $otherCentre, 'amcos_uuid' => $otherAmcos, 'name' => 'Two', 'centre_code' => 'TWO'],
        ]);
        DB::table('cooperative_user_memberships')->insert([
            ['uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'amcos_uuid' => $amcos, 'role' => 'amcos_admin', 'status' => 'approved'],
            ['uuid' => (string) Str::uuid(), 'user_id' => $outsider->id, 'amcos_uuid' => $otherAmcos, 'role' => 'amcos_admin', 'status' => 'approved'],
            ['uuid' => $membership, 'user_id' => $applicant->id, 'amcos_uuid' => $amcos, 'role' => 'centre_clerk', 'status' => 'pending'],
        ]);
        $url = "/api/v2/dairy/amcos-membership/{$membership}/review";
        $this->actingAs($outsider)->postJson($url, ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($admin)->postJson($url, ['decision' => 'approved', 'assigned_centre_uuids' => [$otherCentre]])
            ->assertUnprocessable();
        $this->assertDatabaseHas('cooperative_user_memberships', ['uuid' => $membership, 'status' => 'pending']);
        $this->postJson($url, ['decision' => 'approved', 'assigned_centre_uuids' => [$centre]])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson($url, ['decision' => 'rejected'])->assertStatus(409);
        $this->assertDatabaseHas('cooperative_user_memberships', ['uuid' => $membership, 'status' => 'approved']);
    }

    public function test_request_cannot_promote_itself_or_reactivate_an_inactive_membership(): void
    {
        $user = User::factory()->create();
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Test', 'amcos_code' => 'TEST']);
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255712345678', 'pin_hash' => 'hash']);
        $application = ['amcos_uuid' => $amcos, 'applicant_name' => 'Example User', 'applicant_gender' => 'male', 'applicant_date_of_birth' => '1990-01-02'];
        $this->actingAs($user)->postJson('/api/v2/dairy/amcos-membership', [
            ...$application, 'role' => 'amcos_admin',
        ])->assertUnprocessable();
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'amcos_uuid' => $amcos, 'role' => 'centre_clerk', 'status' => 'inactive',
        ]);
        $this->postJson('/api/v2/dairy/amcos-membership', $application)->assertStatus(409);
        DB::table('cooperative_amcos')->where('uuid', $amcos)->update(['is_active' => false]);
        $this->postJson('/api/v2/dairy/amcos-membership', $application)->assertUnprocessable();
    }

    public function test_union_admin_can_review_when_they_also_have_a_clerk_membership(): void
    {
        $admin = User::factory()->create();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = DB::table('farmers')->insertGetId([
            'farmerNo' => 'FARMER-1', 'firstName' => 'Example', 'surname' => 'Farmer',
            'phone1' => '0799999999', 'gender' => 'female', 'farmerType' => 'individual', 'countryId' => $country,
        ]);
        $applicant = User::factory()->create();
        $mcu = (string) Str::uuid();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $pending = (string) Str::uuid();
        DB::table('cooperative_mcus')->insert(['uuid' => $mcu, 'name' => 'Union', 'mcu_code' => 'UNION']);
        DB::table('cooperative_amcos')->insert([
            ['uuid' => $first, 'mcu_uuid' => $mcu, 'name' => 'One', 'amcos_code' => 'ONE'],
            ['uuid' => $second, 'mcu_uuid' => $mcu, 'name' => 'Two', 'amcos_code' => 'TWO'],
        ]);
        DB::table('cooperative_user_memberships')->insert([
            ['uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'amcos_uuid' => $first, 'mcu_uuid' => $mcu, 'role' => 'centre_clerk', 'status' => 'approved'],
            ['uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'amcos_uuid' => $second, 'mcu_uuid' => $mcu, 'role' => 'mcu_admin', 'status' => 'approved'],
            ['uuid' => $pending, 'user_id' => $applicant->id, 'amcos_uuid' => $first, 'mcu_uuid' => $mcu, 'role' => 'centre_clerk', 'status' => 'pending'],
        ]);
        $this->actingAs($admin)->postJson("/api/v2/dairy/amcos-membership/{$pending}/review", ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_pending_review_includes_linked_farmer_details_only_for_authorised_admin(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = DB::table('farmers')->insertGetId([
            'farmerNo' => 'FARMER-1', 'firstName' => 'Example', 'surname' => 'Farmer',
            'phone1' => '0712345678', 'gender' => 'female', 'dateOfBirth' => '1990-01-02',
            'farmerType' => 'individual', 'countryId' => $country,
        ]);
        $applicant = User::factory()->create(['role' => 'farmer', 'roleId' => $farmer]);
        $admin = User::factory()->create();
        $outsider = User::factory()->create();
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Test', 'amcos_code' => 'TEST']);
        DB::table('cooperative_user_memberships')->insert([
            ['uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'amcos_uuid' => $amcos, 'role' => 'amcos_admin', 'status' => 'approved'],
            ['uuid' => (string) Str::uuid(), 'user_id' => $applicant->id, 'amcos_uuid' => $amcos, 'role' => 'centre_clerk', 'status' => 'pending'],
        ]);
        $url = '/api/v2/dairy/amcos-membership/pending?amcos_uuid='.$amcos;
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.farmer_details.farmer_number', 'FARMER-1')
            ->assertJsonPath('data.0.farmer_details.gender', 'female')
            ->assertJsonPath('data.0.farmer_details.date_of_birth', '1990-01-02');
    }

    public function test_personal_details_are_validated_and_status_endpoint_is_scoped_to_the_current_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Test', 'amcos_code' => 'TEST']);
        $url = '/api/v2/dairy/amcos-membership';
        $data = ['amcos_uuid' => $amcos, 'applicant_name' => 'Jane Applicant', 'applicant_gender' => 'female', 'applicant_date_of_birth' => '1990-01-02'];
        $this->actingAs($user)->postJson($url, $data)->assertUnprocessable();
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255712345678', 'display_name' => 'Jane', 'pin_hash' => 'hash']);
        $this->postJson($url, [...$data, 'applicant_name' => '   '])->assertUnprocessable();
        $this->postJson($url, [...$data, 'applicant_date_of_birth' => '2999-01-01'])->assertUnprocessable();
        $this->postJson($url, $data)->assertCreated()->assertJsonPath('data.applicant_name', 'Jane Applicant');
        $this->getJson($url.'/mine')->assertOk()->assertJsonPath('data.phone', '+255712345678')
            ->assertJsonPath('data.memberships.0.status', 'pending')->assertJsonCount(1, 'data.memberships');
        $this->actingAs($other)->getJson($url.'/mine')->assertOk()
            ->assertJsonPath('data.phone', null)->assertJsonCount(0, 'data.memberships');
    }

    public function test_portal_approvals_and_registry_are_limited_to_admin_membership_scope(): void
    {
        $admin = User::factory()->create(['role' => 'systemUser', 'platform_role' => 'admin']);
        $super = User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']);
        $other = User::factory()->create();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $firstRequest = (string) Str::uuid();
        $secondRequest = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert([
            ['uuid' => $first, 'name' => 'Visible', 'amcos_code' => 'VISIBLE'],
            ['uuid' => $second, 'name' => 'Private', 'amcos_code' => 'PRIVATE'],
        ]);
        DB::table('cooperative_user_memberships')->insert([
            ['uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'amcos_uuid' => $first, 'role' => 'amcos_admin', 'status' => 'approved'],
            ['uuid' => $firstRequest, 'user_id' => $other->id, 'amcos_uuid' => $first, 'role' => 'centre_clerk', 'status' => 'pending'],
            ['uuid' => $secondRequest, 'user_id' => $other->id, 'amcos_uuid' => $second, 'role' => 'centre_clerk', 'status' => 'pending'],
        ]);
        $this->actingAs($admin)->getJson('/api/v1/admin/dairy/cooperatives/amcos')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $first)->assertJsonPath('meta.stats.total', 1);
        $this->getJson('/api/v1/admin/dairy/cooperatives/approvals')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $firstRequest);
        $this->postJson("/api/v1/admin/dairy/cooperatives/approvals/{$secondRequest}/review", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($super)->getJson('/api/v1/admin/dairy/cooperatives/approvals')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/admin/dairy/cooperatives/approvals/{$secondRequest}/review", ['decision' => 'rejected'])->assertOk();
        $this->assertDatabaseHas('cooperative_membership_notifications', ['membership_uuid' => $secondRequest, 'event' => 'rejected', 'status' => 'pending']);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_notification_is_recorded_and_reports_provider_failure_and_acceptance(): void
    {
        $user = User::factory()->create();
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255712345678', 'pin_hash' => 'hash']);
        $uuid = (string) Str::uuid();
        $notifications = app(CooperativeMembershipNotifications::class);
        $notifications->record($uuid, $user->id, 'submitted');
        config(['services.beem_sms.api_key' => null, 'services.beem_sms.secret_key' => null, 'services.beem_sms.sender' => null]);
        $responses = Http::sequence()->push(['successful' => false], 200)->push(['successful' => true], 200);
        Http::fake(['apisms.beem.africa/*' => $responses]);
        $this->assertSame(0, $notifications->dispatchPending());
        Http::assertNothingSent();
        $this->assertDatabaseHas('cooperative_membership_notifications', ['membership_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0]);
        config(['services.beem_sms.api_key' => 'test-key', 'services.beem_sms.secret_key' => 'test-secret', 'services.beem_sms.sender' => 'TEST']);
        $this->assertSame(0, $notifications->dispatchPending());
        $this->assertDatabaseHas('cooperative_membership_notifications', ['membership_uuid' => $uuid, 'status' => 'failed', 'attempts' => 1]);
        DB::table('cooperative_membership_notifications')->where('membership_uuid', $uuid)->update(['next_attempt_at' => now()->subMinute()]);
        $this->assertSame(1, $notifications->dispatchPending());
        $this->assertDatabaseHas('cooperative_membership_notifications', ['membership_uuid' => $uuid, 'status' => 'sent', 'attempts' => 2]);
    }

    public function test_hierarchy_does_not_expose_unrelated_centres_or_cooperatives(): void
    {
        $user = User::factory()->create();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $visible = (string) Str::uuid();
        $hidden = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert([
            ['uuid' => $first, 'name' => 'Visible', 'amcos_code' => 'VISIBLE'],
            ['uuid' => $second, 'name' => 'Private', 'amcos_code' => 'PRIVATE'],
        ]);
        DB::table('cooperative_collection_centres')->insert([
            ['uuid' => $visible, 'amcos_uuid' => $first, 'name' => 'Assigned', 'centre_code' => 'ASSIGNED'],
            ['uuid' => $hidden, 'amcos_uuid' => $second, 'name' => 'Private', 'centre_code' => 'PRIVATE'],
        ]);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'amcos_uuid' => $first,
            'role' => 'centre_clerk', 'status' => 'approved', 'assigned_centre_uuids' => json_encode([$visible]),
        ]);
        $this->actingAs($user)->getJson('/api/v2/dairy/hierarchy')
            ->assertOk()->assertJsonCount(1, 'data.amcos')->assertJsonPath('data.amcos.0.uuid', $first)
            ->assertJsonCount(1, 'data.centres')->assertJsonPath('data.centres.0.uuid', $visible);
        $this->actingAs(User::factory()->create())->getJson('/api/v2/dairy/hierarchy')
            ->assertOk()->assertJsonCount(0, 'data.amcos')->assertJsonCount(0, 'data.centres');
        DB::table('cooperative_amcos')->where('uuid', $first)->update(['is_active' => false]);
        $this->actingAs($user)->getJson('/api/v2/dairy/hierarchy')
            ->assertOk()->assertJsonCount(0, 'data.amcos')->assertJsonCount(0, 'data.my_staff_memberships');
    }

    public function test_applicant_request_portal_approval_and_reentry_status(): void
    {
        $admin = User::factory()->create(['role' => 'systemUser', 'platform_role' => 'superAdmin']);
        $user = User::factory()->create();
        config([
            'services.beem_sms.api_key' => 'test-key',
            'services.beem_sms.secret_key' => 'test-secret',
            'services.beem_sms.sender' => 'TEST',
        ]);
        Http::fake(['apisms.beem.africa/*' => Http::response(['successful' => true], 200)]);
        $amcos = (string) Str::uuid();
        $centre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Cooperative', 'amcos_code' => 'TEST']);
        DB::table('cooperative_collection_centres')->insert(['uuid' => $centre, 'amcos_uuid' => $amcos, 'name' => 'Centre', 'centre_code' => 'CENTRE']);
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255712345678', 'pin_hash' => 'hash']);
        $membership = $this->actingAs($user)->postJson('/api/v2/dairy/amcos-membership', [
            'amcos_uuid' => $amcos, 'applicant_name' => 'Test Applicant', 'applicant_gender' => 'female', 'applicant_date_of_birth' => '1990-01-02',
        ])->assertCreated()->json('data.uuid');
        $this->postJson('/api/v2/dairy/amcos-membership', [
            'amcos_uuid' => $amcos, 'applicant_name' => 'Updated Applicant', 'applicant_gender' => 'female', 'applicant_date_of_birth' => '1990-01-02',
        ])->assertOk();
        $this->getJson('/api/v2/dairy/amcos-membership/mine')->assertOk()->assertJsonPath('data.memberships.0.status', 'pending');
        $this->actingAs($admin)->getJson('/api/v1/admin/dairy/cooperatives/approvals')->assertOk()
            ->assertJsonPath('data.0.applicant_name', 'Updated Applicant')->assertJsonPath('centres.0.uuid', $centre);
        $this->postJson("/api/v1/admin/dairy/cooperatives/approvals/{$membership}/review", [
            'decision' => 'approved', 'role' => 'centre_clerk', 'assigned_centre_uuids' => [$centre],
        ])->assertOk();
        $this->actingAs($user)->getJson('/api/v2/dairy/amcos-membership/mine')
            ->assertOk()->assertJsonPath('data.memberships.0.status', 'approved');
        $this->getJson('/api/v2/dairy/hierarchy')->assertOk()->assertJsonPath('data.centres.0.uuid', $centre);
        $this->assertDatabaseHas('phone_identities', ['user_id' => $user->id, 'display_name' => 'Updated Applicant']);
        $this->assertDatabaseHas('cooperative_user_memberships', ['uuid' => $membership, 'applicant_name' => 'Updated Applicant']);
        $this->assertDatabaseHas('cooperative_membership_notifications', ['membership_uuid' => $membership, 'event' => 'submitted', 'status' => 'sent']);
        $this->assertDatabaseHas('cooperative_membership_notifications', ['membership_uuid' => $membership, 'event' => 'approved', 'status' => 'sent']);
        Http::assertSentCount(2);
    }

    public function test_farmer_cannot_apply_for_cooperative_staff_membership(): void
    {
        $farmerUser = User::factory()->create(['role' => 'farmer']);
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Test AMCOS', 'amcos_code' => 'TEST']);
        DB::table('phone_identities')->insert(['user_id' => $farmerUser->id, 'phone' => '+255711111111', 'pin_hash' => 'hash']);

        $application = [
            'amcos_uuid' => $amcos,
            'applicant_name' => 'Farmer Applicant',
            'applicant_gender' => 'male',
            'applicant_date_of_birth' => '1990-05-15',
        ];

        // Test English locale
        $resEn = $this->actingAs($farmerUser)->withHeader('Accept-Language', 'en')
            ->postJson('/api/v2/dairy/amcos-membership', $application);
        $resEn->assertStatus(422)
            ->assertJsonPath('code', 'FARMER_CANNOT_BE_STAFF')
            ->assertJsonPath('message', 'You are already registered as a farmer. Farmers cannot apply for cooperative staff membership.');

        // Test Swahili locale
        $resSw = $this->actingAs($farmerUser)->withHeader('Accept-Language', 'sw')
            ->postJson('/api/v2/dairy/amcos-membership', $application);
        $resSw->assertStatus(422)
            ->assertJsonPath('code', 'FARMER_CANNOT_BE_STAFF')
            ->assertJsonPath('message', 'Wewe tayari umesajiliwa kama mkulima. Mkulima hawezi kuomba uanachama wa mtendaji wa ushirika (AMCOS).');
    }

    public function test_identity_card_type_and_number_formatting_and_validation(): void
    {
        $user = User::factory()->create();
        $amcos = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Test AMCOS', 'amcos_code' => 'TEST']);
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255722222222', 'pin_hash' => 'hash']);

        $nidaId = DB::table('identity_card_types')->insertGetId(['name' => 'NIDA', 'created_at' => now(), 'updated_at' => now()]);
        $dlId = DB::table('identity_card_types')->insertGetId(['name' => 'LICENCE', 'created_at' => now(), 'updated_at' => now()]);

        $base = [
            'amcos_uuid' => $amcos,
            'applicant_name' => 'Staff Applicant',
            'applicant_gender' => 'female',
            'applicant_date_of_birth' => '1995-03-20',
        ];

        // Invalid NIDA (less than 20 digits)
        $this->actingAs($user)->postJson('/api/v2/dairy/amcos-membership', [
            ...$base,
            'identity_card_type_id' => $nidaId,
            'identity_number' => '19950320-123',
        ])->assertStatus(422);

        // Valid NIDA with formatting
        $res = $this->actingAs($user)->postJson('/api/v2/dairy/amcos-membership', [
            ...$base,
            'identity_card_type_id' => $nidaId,
            'identity_number' => '19950320-12345-67890-12',
        ])->assertCreated();
        $this->assertEquals('19950320-12345-67890-12', $res->json('data.identity_number'));
        $this->assertEquals($nidaId, $res->json('data.identity_card_type_id'));

        // Check mine returns identity card types
        $mine = $this->getJson('/api/v2/dairy/amcos-membership/mine')->assertOk();
        $mine->assertJsonStructure(['data' => ['identity_card_types']]);
    }

    public function test_amcos_user_approved_without_center_assignment_has_full_amcos_access_and_can_create_centers(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $amcos = (string) Str::uuid();
        $centre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $amcos, 'name' => 'Test AMCOS', 'amcos_code' => 'TEST']);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $centre, 'amcos_uuid' => $amcos, 'name' => 'Existing Centre', 'centre_code' => 'EX1', 'default_unit' => 'l',
        ]);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'amcos_uuid' => $amcos, 'role' => 'amcos_admin', 'status' => 'approved',
        ]);
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255733333333', 'pin_hash' => 'hash']);

        // 1. User submits application
        $membership = $this->actingAs($user)->postJson('/api/v2/dairy/amcos-membership', [
            'amcos_uuid' => $amcos, 'applicant_name' => 'Clerk User', 'applicant_gender' => 'male', 'applicant_date_of_birth' => '1992-06-10',
        ])->assertCreated()->json('data.uuid');

        // 2. Admin approves with NO centre assignment (no assigned_centre_uuids passed)
        $this->actingAs($admin)->postJson("/api/v2/dairy/amcos-membership/{$membership}/review", [
            'decision' => 'approved',
            'role' => 'amcos_user',
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.role', 'amcos_user');

        // 3. AMCOS User fetches hierarchy -> sees existing centre
        $hierarchy = $this->actingAs($user)->getJson('/api/v2/dairy/hierarchy')->assertOk();
        $hierarchy->assertJsonPath('data.centres.0.uuid', $centre);

        // 4. AMCOS User creates a new collection centre for their AMCOS!
        $newCentreUuid = (string) Str::uuid();
        $this->postJson('/api/v2/dairy/centres', [
            'uuid' => $newCentreUuid,
            'amcos_uuid' => $amcos,
            'name' => 'User Created Centre',
            'default_unit' => 'l',
        ])->assertCreated()->assertJsonPath('data.uuid', $newCentreUuid);

        $this->assertDatabaseHas('cooperative_collection_centres', [
            'uuid' => $newCentreUuid,
            'name' => 'User Created Centre',
            'amcos_uuid' => $amcos,
        ]);
    }
}
