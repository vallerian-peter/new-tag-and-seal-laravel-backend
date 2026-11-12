<?php

namespace Tests\Feature\Admin;

use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CareerResourcesTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        $profile = SystemUser::create(['firstName' => 'Admin', 'lastName' => 'Test', 'phone' => '+255700000001', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'systemUser', 'roleId' => $profile->id, 'platform_role' => 'superAdmin']);
        $owner = User::factory()->create(['role' => 'appUser']);
        $worker = User::factory()->create(['role' => 'appUser']);
        foreach ([$owner, $worker] as $index => $user) DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+25570000000'.($index + 2), 'display_name' => $index ? 'Candidate' : 'Employer', 'pin_hash' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('feature_memberships')->insert(['user_id' => $owner->id, 'feature' => 'jobOwner', 'details' => json_encode(['name' => 'Individual Employer', 'type' => 'individual']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('feature_memberships')->insert(['user_id' => $worker->id, 'feature' => 'jobSeeker', 'details' => json_encode(['fullName' => 'Candidate', 'skills' => ['Milking']]), 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('career_job_types')->value('id');
        $job = DB::table('career_jobs')->insertGetId(['owner_user_id' => $owner->id, 'job_type_id' => $category, 'title' => 'Milker', 'positions_count' => 2, 'details' => json_encode(['jobNature' => 'Full Time', 'employerName' => 'Individual Employer']), 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = ['fullName' => 'Submitted Candidate', 'documents' => [['id' => 'cv', 'fileName' => 'cv.pdf', 'fileData' => base64_encode('%PDF-1.4 snapshot')]]];
        $application = DB::table('career_applications')->insertGetId(['job_id' => $job, 'applicant_user_id' => $worker->id, 'status' => 'submitted', 'applicant_snapshot' => json_encode($snapshot), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin);
        return compact('admin', 'owner', 'worker', 'job', 'application', 'snapshot');
    }
    public function test_all_registries_use_real_accounts_and_include_individual_employers(): void
    {
        extract($this->fixture());
        $this->getJson('/api/v1/admin/career/overview')->assertOk()->assertJsonPath('data.stats.1.value', 1);
        foreach (['jobs', 'applications', 'job-seekers', 'employers', 'memberships', 'onboardings', 'workers', 'job-types', 'job-options'] as $resource) $this->getJson('/api/v1/admin/career/'.$resource)->assertOk()->assertJsonStructure(['data', 'meta' => ['currentPage', 'lastPage', 'total', 'stats']]);
        $this->getJson('/api/v1/admin/career/employers')->assertOk()->assertJsonPath('data.0.name', 'Individual Employer');
        $this->getJson('/api/v1/admin/career/jobs/'.$job)->assertOk()->assertJsonPath('data.positions_count', 2)->assertJsonPath('data.farm_name', null);
        $this->getJson('/api/v1/admin/career/applications/'.$application)->assertOk()->assertJsonPath('data.applicant_snapshot.documents.0.fileData', $snapshot['documents'][0]['fileData']);
        $this->assertSame('appUser', $owner->fresh()->role);
    }
    public function test_search_filters_pagination_and_real_catalog_mutations(): void
    {
        extract($this->fixture());
        for ($i = 0; $i < 8; $i++) DB::table('career_jobs')->insert(['owner_user_id' => $owner->id, 'title' => 'Milker '.$i, 'details' => '{}', 'positions_count' => 1, 'closed' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/v1/admin/career/jobs?status=closed&per_page=5&page=2&search=Milker')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 8);
        $data = ['code' => 'test_category', 'name_en' => 'Test category', 'name_sw' => 'Aina ya majaribio', 'sort_order' => 10, 'is_active' => true];
        $id = $this->postJson('/api/v1/admin/career/job-types', $data)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/admin/career/job-types/'.$id, [...$data, 'is_active' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->postJson('/api/v1/admin/career/job-types', $data)->assertUnprocessable();
        $this->patchJson('/api/v1/admin/career/jobs/'.$job, ['positions_count' => 3])->assertOk()->assertJsonPath('data.positions_count', 3);
        $this->assertDatabaseHas('audit_logs', ['action' => 'career.admin.updated']);
    }
    public function test_review_does_not_bypass_joining_and_snapshot_is_preserved(): void
    {
        extract($this->fixture());
        $url = '/api/v1/admin/career/applications/'.$application;
        $this->patchJson($url, ['status' => 'hired'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'reserve'])->assertOk()->assertJsonPath('data.status', 'reserve');
        $this->assertSame($snapshot, json_decode(DB::table('career_applications')->where('id', $application)->value('applicant_snapshot'), true));
        $this->actingAs($worker)->getJson('/api/v1/admin/career/memberships')->assertForbidden();
        $this->getJson('/api/v2/memberships')->assertOk()->assertJsonPath('account.phoneVerified', true)->assertJsonCount(1, 'data')->assertJsonCount(0, 'assignments');
    }
    public function test_admin_proposes_a_pending_worker_without_changing_identity_or_granting_access(): void
    {
        extract($this->fixture());
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = \App\Models\Farmer::create(['farmerNo' => 'QA-OWNER', 'firstName' => 'Owner', 'surname' => 'Test', 'phone1' => '+255700000002', 'gender' => 'male', 'farmerType' => 'individual', 'countryId' => $country, 'status' => 'active']);
        DB::table('feature_memberships')->insert(['user_id' => $owner->id, 'feature' => 'farmer', 'farmer_id' => $farmer->id, 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $farm = \App\Models\Farm::create(['farmerId' => $farmer->id, 'uuid' => (string) Str::uuid(), 'referenceNo' => 'TEST', 'name' => 'Test farm', 'size' => 1, 'sizeUnit' => 'acre', 'physicalAddress' => 'Test', 'countryId' => $country, 'status' => 'active']);
        $password = $worker->password;
        $payload = ['requestUuid' => (string) Str::uuid(), 'farmUuid' => $farm->uuid, 'name' => 'Worker', 'phone' => '+255700000003', 'roleTitle' => 'Milker', 'terms' => ['startDate' => '2026-12-01', 'responseDate' => '2026-11-30', 'reportingName' => 'Owner', 'reportingPhone' => '+255700000002']];
        $this->postJson('/api/v1/admin/career/workers', [...$payload, 'activate' => true])->assertUnprocessable();
        $id = $this->postJson('/api/v1/admin/career/workers', $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.access_status', 'pending')->json('data.id');
        $this->postJson('/api/v1/admin/career/workers', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('farm_worker_assignments', 1);
        $this->assertDatabaseCount('farm_access_grants', 0);
        $this->assertSame($password, $worker->fresh()->password);
        $this->assertSame('appUser', $worker->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['actor_uuid' => $admin->uuid, 'action' => 'hiring.manual.registered']);
        $this->assertDatabaseHas('hiring_notifications', ['user_id' => $owner->id, 'kind' => 'worker.pending']);
        $this->postJson('/api/v2/hiring/'.$id.'/activate')->assertForbidden();
    }

    public function test_admin_can_update_job_seeker_and_delete_career_records(): void
    {
        extract($this->fixture());
        $seekerId = DB::table('feature_memberships')->where('user_id', $worker->id)->where('feature', 'jobSeeker')->value('id');

        $this->patchJson('/api/v1/admin/career/job-seekers/'.$seekerId, [
            'status' => 'not-active',
            'preferredRole' => 'Farm Manager',
            'region' => 'Kilimanjaro',
        ])->assertOk()
            ->assertJsonPath('data.status', 'notActive')
            ->assertJsonPath('data.preferred_role', 'Farm Manager');

        $this->deleteJson('/api/v1/admin/career/job-seekers/'.$seekerId)
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseMissing('feature_memberships', ['id' => $seekerId]);
    }

}
