<?php

namespace Tests\Feature;

use App\Jobs\SendHiringSms;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\FarmUser;
use App\Models\User;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Hiring\GrantSources;
use App\Services\Hiring\OnboardingService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\PublishesConfiguration;
use Tests\TestCase;

class HiringWorkflowTest extends TestCase
{
    use PublishesConfiguration;
    use RefreshDatabase;

    private function fixture(): array
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = Farmer::create(['farmerNo' => 'F-1', 'firstName' => 'Owner', 'surname' => 'One', 'phone1' => '0712345678', 'gender' => 'male', 'farmerType' => 'individual', 'countryId' => $country, 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'farmer', 'roleId' => $farmer->id]);
        $worker = User::factory()->create();
        DB::table('phone_identities')->insert(['user_id' => $worker->id, 'phone' => '+255712345679', 'pin_hash' => Hash::make('1234'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('phone_identities')->insert(['user_id' => $owner->id, 'phone' => '+255712345678', 'pin_hash' => Hash::make('1234'), 'created_at' => now(), 'updated_at' => now()]);
        $farm = Farm::create(['farmerId' => $farmer->id, 'uuid' => 'farm-one', 'referenceNo' => 'FARM', 'name' => 'Meru Farm', 'size' => 1, 'sizeUnit' => 'acre', 'physicalAddress' => 'Arusha', 'countryId' => $country, 'status' => 'active']);
        DB::table('feature_memberships')->insert(['user_id' => $owner->id, 'feature' => 'jobOwner', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $job = DB::table('career_jobs')->insertGetId(['owner_user_id' => $owner->id, 'title' => 'Milker', 'details' => '{}', 'positions_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $application = DB::table('career_applications')->insertGetId(['job_id' => $job, 'applicant_user_id' => $worker->id, 'applicant_snapshot' => json_encode(['fullName' => 'Candidate', 'phone' => '+255712345679', 'resumeData' => 'aW1tdXRhYmxl']), 'status' => 'shortlisted', 'created_at' => now(), 'updated_at' => now()]);

        return compact('owner', 'worker', 'farm', 'job', 'application');
    }

    private function terms(string $farm = 'farm-one'): array
    {
        return ['requestUuid' => (string) Str::uuid(), 'farmUuid' => $farm, 'roleTitle' => 'Milking worker', 'permissions' => ['farm.view', 'work.milking.view', 'work.milking.create'], 'terms' => ['startDate' => today()->addWeek()->toDateString(), 'responseDate' => today()->addDays(3)->toDateString(), 'reportingName' => 'Owner', 'reportingPhone' => '+255712345678', 'instructions' => 'Report to the office']];
    }

    public function test_joining_response_never_grants_access_and_activation_is_idempotent(): void
    {
        extract($this->fixture());
        $this->actingAs($owner)->patchJson("/api/v2/career/jobs/$job/applications/$application", ['status' => 'hired'])->assertUnprocessable();
        $data = $this->terms();
        $joining = $this->postJson("/api/v2/career/applications/$application/joining", $data)->assertOk()->json('data.id');
        $this->postJson("/api/v2/career/applications/$application/joining", $data)->assertOk()->assertJsonPath('data.id', $joining);
        $this->assertFalse(app(FarmAccessService::class)->canAccess($worker, $farm->uuid));
        $this->postJson("/api/v2/hiring/$joining/activate")->assertStatus(409);
        $this->actingAs($worker)->postJson("/api/v2/hiring/$joining/response", ['accept' => true, 'availability' => 'Available', 'arrivalDate' => today()->addDays(5)->toDateString(), 'phone' => '+255712345679'])->assertOk();
        $this->assertFalse(app(FarmAccessService::class)->canAccess($worker, $farm->uuid));
        $this->postJson("/api/v2/hiring/$joining/activate")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v2/hiring/$joining/activate")->assertOk()->assertJsonPath('data.status', 'activated');
        $this->postJson("/api/v2/hiring/$joining/activate")->assertOk();
        $this->assertDatabaseCount('farm_worker_assignments', 1);
        $this->assertTrue(app(FarmAccessService::class)->canModule($worker, $farm->uuid, 'milking', 'create'));
        $this->assertFalse(app(FarmAccessService::class)->canModule($worker, $farm->uuid, 'feeding', 'create'));
        $this->assertFalse(app(FarmAccessService::class)->can($worker, $farm->uuid, 'finance.manage'));
        $this->assertDatabaseHas('career_applications', ['id' => $application, 'status' => 'hired']);
        $snapshot = json_decode(DB::table('career_applications')->where('id', $application)->value('applicant_snapshot'), true);
        $this->assertSame('aW1tdXRhYmxl', $snapshot['resumeData']);
    }

    public function test_capacity_reserves_replacements_and_source_specific_revocation(): void
    {
        extract($this->fixture());
        $joining = $this->actingAs($owner)->postJson("/api/v2/career/applications/$application/joining", $this->terms())->assertOk()->json('data.id');
        $other = User::factory()->create();
        $second = DB::table('career_applications')->insertGetId(['job_id' => $job, 'applicant_user_id' => $other->id, 'status' => 'reserve', 'applicant_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v2/career/applications/$second/joining", $this->terms())->assertUnprocessable();
        $this->actingAs($worker)->postJson("/api/v2/hiring/$joining/response", ['accept' => true, 'availability' => 'yes', 'arrivalDate' => today()->toDateString(), 'phone' => '+255712345679'])->assertOk();
        $this->actingAs($owner)->postJson("/api/v2/hiring/$joining/activate")->assertOk();
        app(GrantSources::class)->write($farm->uuid, $worker->id, 'manual', 'independent', ['farm.view', 'work.feeding.view'], $owner->id);
        $this->postJson("/api/v2/hiring/$joining/end", ['status' => 'ended', 'reason' => 'Employment ended'])->assertOk();
        $this->assertFalse(app(FarmAccessService::class)->canModule($worker, $farm->uuid, 'milking', 'create'));
        $this->assertTrue(app(FarmAccessService::class)->canModule($worker, $farm->uuid, 'feeding', 'view'));
        $this->postJson("/api/v2/career/applications/$second/joining", $this->terms())->assertOk();
        $this->assertDatabaseHas('career_onboardings', ['id' => $joining, 'status' => 'ended']);
    }

    public function test_manual_registration_reuses_identity_without_role_or_password_changes(): void
    {
        extract($this->fixture());
        $password = $worker->password;
        $role = $worker->role;
        $data = [...$this->terms(), 'name' => 'Worker', 'phone' => '0712345679', 'activate' => true];
        $this->actingAs($owner)->postJson('/api/v2/farms/farm-one/workers', $data)->assertOk()->assertJsonPath('data.status', 'activated');
        $this->assertSame($password, $worker->fresh()->password);
        $this->assertSame($role, $worker->fresh()->role);
        $this->assertDatabaseCount('users', 2);
        $pending = $this->postJson('/api/v2/farms/farm-one/workers', [...$data, 'requestUuid' => (string) Str::uuid(), 'phone' => '0712345680'])->assertOk()->assertJsonPath('data.user_id', null)->json('data.id');
        $this->assertDatabaseCount('users', 2);
        $this->postJson("/api/v2/hiring/$pending/activate")->assertUnprocessable();
        $new = User::factory()->create();
        DB::table('phone_identities')->insert(['user_id' => $new->id, 'phone' => '+255712345680', 'pin_hash' => 'hash', 'created_at' => now(), 'updated_at' => now()]);
        app(OnboardingService::class)->claim($new);
        $this->postJson("/api/v2/hiring/$pending/activate")->assertOk();
    }

    public function test_notifications_survive_sms_failure_and_success_is_not_sent_twice(): void
    {
        extract($this->fixture());
        $this->actingAs($owner)->postJson('/api/v2/farms/farm-one/workers', [...$this->terms(), 'name' => 'Worker', 'phone' => '0712345679'])->assertOk();
        $id = DB::table('hiring_notifications')->value('id');
        $failure = \Mockery::mock(SmsService::class);
        $failure->shouldReceive('sendSms')->once()->andReturn('unavailable');
        try {
            (new SendHiringSms($id))->handle($failure);
            $this->fail('Expected retry');
        } catch (\RuntimeException $error) {
            $this->assertSame('SMS_DELIVERY_FAILED', $error->getMessage());
        }
        $this->assertDatabaseHas('hiring_notifications', ['id' => $id, 'sms_status' => 'failed']);
        $success = \Mockery::mock(SmsService::class);
        $success->shouldReceive('sendSms')->once()->andReturn(['status' => 'success']);
        (new SendHiringSms($id))->handle($success);
        (new SendHiringSms($id))->handle($success);
        $this->assertDatabaseHas('hiring_notifications', ['id' => $id, 'sms_status' => 'sent']);
    }

    public function test_farm_authorization_and_applicant_decline_release_capacity_without_access(): void
    {
        extract($this->fixture());
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->postJson("/api/v2/career/applications/$application/joining", $this->terms())->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v2/career/applications/$application/joining", $this->terms('missing-farm'))->assertUnprocessable();
        $id = $this->postJson("/api/v2/career/applications/$application/joining", $this->terms())->assertOk()->json('data.id');
        $this->actingAs($outsider)->getJson("/api/v2/hiring/$id")->assertForbidden();
        $this->actingAs($worker)->postJson("/api/v2/hiring/$id/response", ['accept' => false, 'message' => 'Unavailable'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertSame(0, app(OnboardingService::class)->occupied($job));
        $this->assertFalse(app(FarmAccessService::class)->canAccess($worker, $farm->uuid));
        $this->actingAs($owner)->postJson("/api/v2/hiring/$id/activate")->assertStatus(409);
        $this->postJson("/api/v2/career/applications/$application/joining", $this->terms())->assertOk();
        $this->assertDatabaseCount('career_onboardings', 2);
    }

    public function test_overdue_reminders_do_not_expire_or_duplicate_and_inbox_is_private(): void
    {
        extract($this->fixture());
        $terms = $this->terms();
        $terms['terms']['responseDate'] = today()->subDay()->toDateString();
        $id = $this->actingAs($owner)->postJson("/api/v2/career/applications/$application/joining", $terms)->assertOk()->assertJsonPath('data.overdue', true)->json('data.id');
        $this->postJson("/api/v2/hiring/$id/reminder")->assertOk();
        $this->postJson("/api/v2/hiring/$id/reminder")->assertOk();
        $this->assertSame(1, DB::table('hiring_notifications')->where('kind', 'joining.reminder')->count());
        $this->assertSame(1, app(OnboardingService::class)->occupied($job));
        $notice = DB::table('hiring_notifications')->where('kind', 'joining.reminder')->value('id');
        $this->postJson("/api/v2/hiring-notifications/$notice/read")->assertNotFound();
        $this->actingAs($worker)->getJson('/api/v2/hiring-notifications')->assertOk()->assertJsonPath('unread', 2);
        $this->postJson("/api/v2/hiring-notifications/$notice/read")->assertOk();
    }

    public function test_explicit_owner_relationship_works_without_changing_global_role(): void
    {
        extract($this->fixture());
        DB::table('feature_memberships')->insert(['user_id' => $owner->id, 'feature' => 'farmer', 'farmer_id' => $owner->roleId, 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $owner->forceFill(['role' => 'appUser', 'roleId' => 0])->save();
        $this->actingAs($owner)->getJson('/api/v2/hiring/options')->assertOk()->assertJsonPath('data.farms.0.uuid', $farm->uuid);
        $this->postJson("/api/v2/career/applications/$application/joining", $this->terms())->assertOk();
        $this->assertSame('appUser', $owner->fresh()->role);
    }

    public function test_ended_placement_keeps_explicit_denial_despite_legacy_role_fallback(): void
    {
        extract($this->fixture());
        $profile = FarmUser::create(['uuid' => (string) Str::uuid(), 'farmUuid' => $farm->uuid, 'firstName' => 'Worker', 'lastName' => 'One', 'email' => 'legacy@example.test', 'phone' => '0712345679', 'gender' => 'male', 'roleTitle' => 'milking-user', 'status' => 'active']);
        $worker->forceFill(['role' => 'farmInvitedUser', 'roleId' => $profile->id])->save();
        $id = $this->actingAs($owner)->postJson('/api/v2/farms/farm-one/workers', [...$this->terms(), 'name' => 'Worker', 'phone' => '0712345679', 'activate' => true])->assertOk()->json('data.id');
        $this->postJson("/api/v2/hiring/$id/end", ['status' => 'ended', 'reason' => 'Contract complete'])->assertOk();
        $this->assertDatabaseHas('farm_access_grants', ['user_id' => $worker->id, 'farm_uuid' => $farm->uuid, 'permissions' => '[]']);
        $this->assertFalse(app(FarmAccessService::class)->canAccess($worker, $farm->uuid));
        $this->assertSame('active', $profile->fresh()->status);
        $this->actingAs($worker)->getJson('/api/v2/hiring/my-access')->assertOk()->assertJsonPath('data', []);
    }

    public function test_additive_migration_preserves_historical_hires_and_links_legacy_workers(): void
    {
        extract($this->fixture());
        DB::table('career_applications')->where('id', $application)->update(['status' => 'hired']);
        $other = User::factory()->create();
        DB::table('career_applications')->insert(['job_id' => $job, 'applicant_user_id' => $other->id, 'status' => 'hired', 'applicant_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $profile = FarmUser::create(['uuid' => (string) Str::uuid(), 'farmUuid' => $farm->uuid, 'firstName' => 'Legacy', 'lastName' => 'Worker', 'email' => 'legacy@example.test', 'phone' => '0712345681', 'gender' => 'male', 'roleTitle' => 'milking-user', 'status' => 'active']);
        $legacy = User::factory()->create(['role' => 'farmInvitedUser', 'roleId' => $profile->id]);
        $password = $legacy->password;
        $migration = require database_path('migrations/2026_11_04_000001_connect_hiring_to_farm_workers.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('career_jobs', ['id' => $job, 'positions_count' => 2]);
        $this->assertSame(0, DB::table('career_onboardings')->whereNotNull('application_id')->count());
        $this->assertSame(2, app(OnboardingService::class)->occupied($job));
        $this->assertFalse(app(FarmAccessService::class)->canAccess($worker, $farm->uuid));
        $this->assertSame($password, $legacy->fresh()->password);
        $this->assertDatabaseHas('farm_worker_assignments', ['user_id' => $legacy->id, 'farm_uuid' => $farm->uuid, 'status' => 'active']);
        $this->assertTrue(app(FarmAccessService::class)->canModule($legacy, $farm->uuid, 'milking', 'create'));
        $placement = DB::table('career_onboardings')->where('user_id', $legacy->id)->value('id');
        $this->actingAs($owner)->postJson("/api/v2/hiring/$placement/end", ['status' => 'ended', 'reason' => 'Legacy contract ended'])->assertOk();
        $this->assertFalse(app(FarmAccessService::class)->canAccess($legacy, $farm->uuid));
    }

    public function test_scoped_event_reads_sync_and_revoked_offline_retries_are_rejected(): void
    {
        extract($this->fixture());
        $species = $this->draftSpecies('hiring_test');
        $field = ['field_uuid' => (string) Str::uuid(), 'key' => 'notes', 'label' => ['en' => 'Notes'], 'component' => 'text', 'value_type' => 'string', 'data_owner' => 'user_input', 'required' => 'always', 'nullable' => false, 'validation' => []];
        $registration = $this->publishedForm('hiring.registration', 'registration', [$field]);
        $form = $this->publishedForm('hiring.event', 'event', [$field]);
        $blueprint = $this->draftBlueprint($species, 'hiring.standard', $registration);
        $milk = $this->draftEvent($blueprint, 'hiring_milk', $form, 'record_only', ['access_module_code' => 'milking']);
        $feed = $this->draftEvent($blueprint, 'hiring_feed', $form, 'record_only', ['access_module_code' => 'feeding']);
        $this->publishBlueprint($blueprint);
        $subject = $this->actingAs($owner)->postJson('/api/v1/dynamic/subjects', ['farmUuid' => $farm->uuid, 'blueprintUuid' => $blueprint, 'blueprintVersion' => 1, 'unitType' => 'individual', 'payload' => ['notes' => 'Animal'], 'idempotencyUuid' => (string) Str::uuid()])->assertCreated()->json('data.uuid');
        $id = $this->postJson('/api/v2/farms/farm-one/workers', [...$this->terms(), 'name' => 'Worker', 'phone' => '0712345679', 'activate' => true])->assertOk()->json('data.id');
        $input = ['eventDefinitionUuid' => $milk, 'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => 1, 'payload' => ['notes' => 'Milking']];
        $this->actingAs($worker)->postJson("/api/v1/dynamic/subjects/$subject/events", $input)->assertCreated();
        $this->postJson("/api/v1/dynamic/subjects/$subject/events", [...$input, 'eventDefinitionUuid' => $feed, 'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => 2])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/dynamic/subjects/$subject/events", [...$input, 'eventDefinitionUuid' => $feed, 'idempotencyUuid' => (string) Str::uuid(), 'baseRevision' => 2])->assertCreated();
        $this->actingAs($worker)->getJson("/api/v1/dynamic/subjects/$subject/events")->assertOk()->assertJsonCount(1, 'data');
        $changes = $this->getJson('/api/v1/sync/changes?farmUuid='.$farm->uuid)->assertOk()->json('data');
        $this->assertCount(1, array_filter($changes, fn ($row) => $row['entityType'] === 'event_record'));
        $this->actingAs($owner)->postJson("/api/v2/hiring/$id/end", ['status' => 'ended', 'reason' => 'Contract ended'])->assertOk();
        $this->actingAs($worker)->postJson("/api/v1/dynamic/subjects/$subject/events", $input)->assertForbidden();
        $this->getJson('/api/v1/sync/changes?farmUuid='.$farm->uuid)->assertForbidden();
    }

    public function test_direct_permission_editor_does_not_copy_a_placement_into_an_independent_grant(): void
    {
        extract($this->fixture());
        $id = $this->actingAs($owner)->postJson('/api/v2/farms/farm-one/workers', [...$this->terms(), 'name' => 'Worker', 'phone' => '0712345679', 'activate' => true])->assertOk()->json('data.id');
        $this->getJson('/api/v2/farms/farm-one/members')->assertOk()->assertJsonPath('data.0.permissions', []);
        $permissions = ['farm.view', 'finance.manage'];
        $this->putJson('/api/v2/farms/farm-one/members/'.$worker->id, ['permissions' => $permissions])->assertOk();
        $notices = DB::table('hiring_notifications')->count();
        $this->putJson('/api/v2/farms/farm-one/members/'.$worker->id, ['permissions' => $permissions])->assertOk();
        $this->assertSame($notices, DB::table('hiring_notifications')->count());
        $this->postJson("/api/v2/hiring/$id/end", ['status' => 'ended', 'reason' => 'Contract ended'])->assertOk();
        $access = app(FarmAccessService::class);
        $this->assertFalse($access->canModule($worker, $farm->uuid, 'milking', 'create'));
        $this->assertTrue($access->can($worker, $farm->uuid, 'finance.manage'));
    }
}
