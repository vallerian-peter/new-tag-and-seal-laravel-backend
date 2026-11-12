<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CareerMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_types_are_required_and_can_filter_vacancies(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/v2/memberships', [
            'feature' => 'jobOwner',
            'details' => ['name' => 'Meru Farm', 'phone' => '+255712345678', 'countryId' => $country, 'type' => 'individual'],
        ])->assertOk();

        $types = $this->actingAs($owner)->getJson('/api/v2/career/catalog')
            ->assertOk()->json('data.jobTypes');
        $this->assertNotEmpty($types);
        $this->actingAs($owner)->getJson('/api/v2/career/catalog')
            ->assertJsonFragment(['group' => 'jobNature', 'code' => 'Full Time']);
        $dairy = collect($types)->firstWhere('code', 'dairy')['id'];
        $vet = collect($types)->firstWhere('code', 'veterinary')['id'];
        $payload = ['title' => 'Worker', 'details' => ['description' => 'Care for animals', 'countryId' => $country]];

        $this->actingAs($owner)->postJson('/api/v2/career/jobs', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('jobTypeId');
        $this->actingAs($owner)->postJson('/api/v2/career/jobs', [...$payload, 'jobTypeId' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('jobTypeId');
        $this->actingAs($owner)->postJson('/api/v2/career/jobs', [
            ...$payload, 'jobTypeId' => $dairy,
            'details' => [...$payload['details'], 'jobNature' => 'unlisted'],
        ])->assertUnprocessable()->assertJsonValidationErrors('details.jobNature');
        $this->actingAs($owner)->postJson('/api/v2/career/jobs', [...$payload, 'jobTypeId' => $dairy])
            ->assertCreated()->assertJsonPath('data.job_type_id', $dairy);
        $this->actingAs($owner)->postJson('/api/v2/career/jobs', [...$payload, 'jobTypeId' => $vet])
            ->assertCreated();

        $this->actingAs($owner)->getJson('/api/v2/career/jobs?jobTypeId='.$dairy)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.job_type_id', $dairy);
        $this->assertDatabaseHas('career_jobs', ['job_type_id' => $dairy]);
    }

    public function test_optional_applicant_preferences_and_letter_address_are_saved_with_a_job(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/v2/memberships', [
            'feature' => 'jobOwner',
            'details' => ['name' => 'Asha Mrema', 'phone' => '+255712345678', 'countryId' => $country, 'type' => 'individual'],
        ])->assertOk();

        $details = [
            'description' => 'Care for animals',
            'countryId' => $country,
            'preferredSex' => 'female',
            'ageRange' => '18–35',
            'allowsCoverLetter' => true,
        ];
        $this->actingAs($owner)->postJson('/api/v2/career/jobs', [
            'title' => 'Farm worker', 'jobTypeId' => 1, 'details' => $details,
        ])->assertStatus(422)->assertJsonPath('code', 'APPLICATION_LETTER_ADDRESS_REQUIRED');

        $this->actingAs($owner)->postJson('/api/v2/career/jobs', [
            'title' => 'Farm worker',
            'jobTypeId' => 1,
            'details' => [...$details, 'applicationLetterAddress' => 'Hiring Office, P.O. Box 42, Arusha'],
        ])->assertCreated()
            ->assertJsonPath('data.details.preferredSex', 'female')
            ->assertJsonPath('data.details.ageRange', '18–35')
            ->assertJsonPath('data.details.applicationLetterAddress', 'Hiring Office, P.O. Box 42, Arusha');
    }

    public function test_career_memberships_and_owned_job_applications_are_isolated(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create();
        $seeker = User::factory()->create();
        $other = User::factory()->create();
        $ownerDetails = ['name' => 'Owner', 'phone' => '+255712345678', 'countryId' => $country, 'type' => 'individual'];
        $seekerDetails = ['name' => 'Seeker', 'phone' => '+255712345679', 'countryId' => $country, 'skills' => ['Dairy']];
        $this->actingAs($owner)->postJson('/api/v2/career/jobs', ['title' => 'Farm worker', 'jobTypeId' => 1, 'details' => ['description' => 'Care for animals', 'countryId' => $country]])->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v2/memberships', ['feature' => 'jobOwner', 'details' => $ownerDetails])->assertOk();
        $this->actingAs($owner)->postJson('/api/v2/memberships', ['feature' => 'jobSeeker', 'details' => $seekerDetails])->assertOk();
        $this->assertDatabaseCount('feature_memberships', 2);
        $job = $this->actingAs($owner)->postJson('/api/v2/career/jobs', [
            'title' => 'Farm worker', 'jobTypeId' => 1, 'details' => ['description' => 'Care for animals', 'countryId' => $country, 'employerName' => 'Unregistered name'],
        ])->assertCreated()->assertJsonPath('data.details.employerName', 'Owner')->json('data.id');
        $this->actingAs($seeker)->postJson('/api/v2/career/jobs/'.$job.'/apply')->assertForbidden();
        $this->actingAs($seeker)->postJson('/api/v2/memberships', ['feature' => 'jobSeeker', 'details' => $seekerDetails])->assertOk();
        $application = $this->actingAs($seeker)->postJson('/api/v2/career/jobs/'.$job.'/apply')->assertCreated()->json('data.id');
        $this->actingAs($seeker)->postJson('/api/v2/memberships', ['feature' => 'jobSeeker', 'details' => array_merge($seekerDetails, ['name' => 'Updated'])])->assertOk();
        $this->actingAs($owner)->getJson('/api/v2/career/jobs/'.$job.'/applications')->assertOk()->assertJsonPath('data.0.applicant_snapshot.name', 'Seeker');
        $this->actingAs($seeker)->postJson('/api/v2/career/jobs/'.$job.'/apply')->assertStatus(409);
        $this->actingAs($other)->postJson('/api/v2/memberships', ['feature' => 'jobOwner', 'details' => $ownerDetails])->assertOk();
        $this->actingAs($other)->getJson('/api/v2/career/jobs/'.$job.'/applications')->assertForbidden();
        $this->actingAs($other)->postJson('/api/v2/career/jobs/'.$job.'/close')->assertForbidden();
        $this->actingAs($owner)->patchJson('/api/v2/career/jobs/'.$job.'/applications/'.$application, ['status' => 'shortlisted'])->assertOk();
        $this->actingAs($owner)->postJson('/api/v2/career/jobs/'.$job.'/close')->assertOk();
        $this->actingAs($seeker)->getJson('/api/v2/career/jobs')->assertJsonCount(0, 'data');
    }

    public function test_cannot_use_another_users_organization(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $organization = $this->actingAs($owner)->postJson('/api/v2/organizations', ['name' => 'Farm Ltd'])->assertCreated()->json('data.id');
        $this->actingAs($other)->postJson('/api/v2/memberships', [
            'feature' => 'jobOwner', 'organizationId' => $organization,
            'details' => ['name' => 'Farm Ltd', 'phone' => '+255712345678', 'countryId' => $country, 'type' => 'organization'],
        ])->assertForbidden();
    }

    public function test_employer_can_grant_scoped_staff_access_and_revoke_it(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $staff = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        DB::table('phone_identities')->insert(['user_id' => $staff->id, 'phone' => '+255712345600', 'display_name' => 'Team member', 'pin_hash' => 'unused']);
        $this->actingAs($owner)->postJson('/api/v2/memberships', [
            'feature' => 'jobOwner', 'details' => ['name' => 'Owner', 'phone' => '+255712345678', 'countryId' => $country, 'type' => 'individual'],
        ])->assertOk();
        $this->actingAs($staff)->getJson('/api/v2/career/employer/members/search?phone=0712345600')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v2/career/employer/members/search?phone=0712345600')->assertOk()->assertJsonPath('data.id', $staff->id);
        $job = $this->actingAs($owner)->postJson('/api/v2/career/jobs', [
            'title' => 'Farm worker', 'jobTypeId' => 1, 'details' => ['description' => 'Care for animals', 'countryId' => $country],
        ])->assertCreated()->json('data.id');
        $this->actingAs($staff)->putJson('/api/v2/career/employer/members/'.$owner->id, ['permissions' => ['jobs.view']])->assertForbidden();
        $this->actingAs($owner)->putJson('/api/v2/career/employer/members/'.$staff->id, ['permissions' => ['jobs.view', 'applications.view']])->assertOk();
        $this->actingAs($staff)->getJson('/api/v2/career/jobs?mine=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($staff)->getJson('/api/v2/career/jobs/'.$job.'/applications')->assertOk();
        $this->actingAs($staff)->postJson('/api/v2/career/jobs/'.$job.'/close')->assertForbidden();
        $this->actingAs($owner)->putJson('/api/v2/career/employer/members/'.$staff->id, ['permissions' => []])->assertOk();
        $this->actingAs($staff)->getJson('/api/v2/career/jobs?mine=1')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($staff)->getJson('/api/v2/career/jobs/'.$job.'/applications')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'employer.permissions.updated', 'entity_uuid' => $staff->uuid]);
    }

    public function test_farmer_membership_creates_farmer_profile_and_shares_organization(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $user = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);

        // 1. Create an organization once
        $orgId = $this->actingAs($user)->postJson('/api/v2/organizations', [
            'name' => 'Kilimo Bora Enterprises',
            'registrationNumber' => 'BRELA-12345',
        ])->assertCreated()->json('data.id');

        // 2. Register Farmer membership with the organization
        $this->actingAs($user)->postJson('/api/v2/memberships', [
            'feature' => 'farmer',
            'organizationId' => $orgId,
            'details' => [
                'name' => 'Rashid Mussa',
                'phone' => '+255711223344',
                'countryId' => $country,
                'farmerType' => 'organization',
                'gender' => 'male',
                'identityNumber' => '19900101-12345-00001-20',
            ],
        ])->assertOk();

        // Verify farmer record created and user updated
        $this->assertDatabaseHas('farmers', [
            'phone1' => '+255711223344',
            'firstName' => 'Rashid',
            'surname' => 'Mussa',
            'farmerType' => 'organization',
        ]);
        $this->assertDatabaseHas('feature_memberships', [
            'user_id' => $user->id,
            'feature' => 'farmer',
            'organization_id' => $orgId,
        ]);
        $this->assertSame('appUser', $user->fresh()->role);
        $this->assertNotNull(DB::table('feature_memberships')->where('user_id', $user->id)->where('feature', 'farmer')->value('farmer_id'));

        // 3. User can also register as jobOwner with the SAME organization without creating it again
        $this->actingAs($user)->postJson('/api/v2/memberships', [
            'feature' => 'jobOwner',
            'organizationId' => $orgId,
            'details' => [
                'name' => 'Rashid Mussa',
                'phone' => '+255711223344',
                'countryId' => $country,
                'type' => 'organization',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('feature_memberships', [
            'user_id' => $user->id,
            'feature' => 'jobOwner',
            'organization_id' => $orgId,
        ]);
        $this->actingAs($user)->postJson('/api/v2/career/jobs', [
            'title' => 'Farm worker', 'jobTypeId' => 1, 'details' => ['description' => 'Care for animals', 'countryId' => $country],
        ])->assertCreated()->assertJsonPath('data.details.employerName', 'Kilimo Bora Enterprises');
    }

    public function test_memberships_expose_staff_delegated_workspaces_only_while_grants_are_active(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $staff = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $stranger = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $this->actingAs($owner)->postJson('/api/v2/memberships', [
            'feature' => 'jobOwner', 'details' => ['name' => 'Owner', 'phone' => '+255712345678', 'countryId' => $country, 'type' => 'individual'],
        ])->assertOk();

        $this->actingAs($staff)->getJson('/api/v2/memberships')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('delegated', []);

        $this->actingAs($owner)->putJson('/api/v2/career/employer/members/'.$staff->id, ['permissions' => ['jobs.view']])->assertOk();
        $this->actingAs($staff)->getJson('/api/v2/memberships')->assertOk()->assertJsonPath('delegated', ['jobOwner']);
        $this->actingAs($stranger)->getJson('/api/v2/memberships')->assertOk()->assertJsonPath('delegated', []);

        DB::table('farm_access_grants')->insert([
            'farm_uuid' => '11111111-1111-4111-8111-111111111111', 'user_id' => $staff->id,
            'permissions' => json_encode(['livestock.view']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($staff)->getJson('/api/v2/memberships')->assertOk()->assertJsonPath('delegated', ['jobOwner', 'farmer']);

        $this->actingAs($owner)->putJson('/api/v2/career/employer/members/'.$staff->id, ['permissions' => []])->assertOk();
        DB::table('farm_access_grants')->where('user_id', $staff->id)->update(['permissions' => json_encode([])]);
        $this->actingAs($staff)->getJson('/api/v2/memberships')->assertOk()->assertJsonPath('delegated', []);
    }

    public function test_application_letter_attachments_follow_the_job_flag_and_are_preserved(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create();
        $seeker = User::factory()->create();
        $this->actingAs($seeker)->postJson('/api/v2/memberships', [
            'feature' => 'jobSeeker', 'details' => ['name' => 'Seeker', 'phone' => '+255712345679', 'countryId' => $country],
        ])->assertOk();
        $createJob = fn (bool $allows, bool $requires = false) => DB::table('career_jobs')->insertGetId([
            'owner_user_id' => $owner->id, 'title' => 'Worker',
            'details' => json_encode(['allowsCoverLetter' => $allows, 'requiresCoverLetter' => $requires]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attachment = ['id' => 'letter', 'title' => 'Application Letter', 'fileName' => 'letter.pdf',
            'fileData' => base64_encode('letter contents'), 'type' => 'application_letter'];
        $disabled = $createJob(false);
        $this->postJson('/api/v2/career/jobs/'.$disabled.'/apply', ['documents' => [$attachment]])
            ->assertUnprocessable()->assertJsonPath('code', 'COVER_LETTER_NOT_ALLOWED');
        $this->postJson('/api/v2/career/jobs/'.$disabled.'/apply', ['profileSnapshot' => ['documents' => [$attachment]]])
            ->assertUnprocessable()->assertJsonPath('code', 'COVER_LETTER_NOT_ALLOWED');
        $this->postJson('/api/v2/career/jobs/'.$disabled.'/apply', ['documents' => []])->assertCreated();
        $enabled = $createJob(true);
        $this->postJson('/api/v2/career/jobs/'.$enabled.'/apply', ['documents' => []])->assertCreated();
        $required = $createJob(true, true);
        $this->postJson('/api/v2/career/jobs/'.$required.'/apply', ['documents' => []])
            ->assertUnprocessable()->assertJsonPath('code', 'COVER_LETTER_REQUIRED');
        $this->postJson('/api/v2/career/jobs/'.$required.'/apply', ['documents' => [[...$attachment, 'fileData' => '']]])
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_APPLICATION_LETTER_ATTACHMENT');
        $this->postJson('/api/v2/career/jobs/'.$required.'/apply', ['documents' => [$attachment]])->assertCreated();
        $saved = DB::table('career_applications')->where('job_id', $required)->first();
        $snapshot = json_decode($saved->applicant_snapshot, true);
        $this->assertSame($attachment['fileData'], $snapshot['documents'][0]['fileData']);
        $this->assertNull($saved->cover_letter);
    }

    public function test_owner_decisions_preserve_submitted_files_and_are_visible_to_the_seeker(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create();
        $seeker = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/v2/memberships', ['feature' => 'jobOwner', 'details' => [
            'name' => 'Meru Farm', 'phone' => '+255712345678', 'countryId' => $country, 'type' => 'individual',
        ]])->assertOk();
        $job = $this->postJson('/api/v2/career/jobs', ['title' => 'Dairy worker', 'jobTypeId' => 1, 'details' => [
            'description' => 'Work with our dairy herd', 'countryId' => $country, 'allowsCoverLetter' => true,
            'applicationLetterAddress' => 'Hiring Office, Arusha',
        ]])->assertCreated()->json('data.id');
        $this->actingAs($seeker)->postJson('/api/v2/memberships', ['feature' => 'jobSeeker', 'details' => [
            'name' => 'Asha Mrema', 'phone' => '+255712345679', 'countryId' => $country,
        ]])->assertOk();
        $documents = [
            ['id' => 'cv', 'title' => 'CV', 'fileName' => 'cv.pdf', 'fileData' => base64_encode('submitted CV'), 'type' => 'cv'],
            ['id' => 'letter', 'title' => 'Application Letter', 'fileName' => 'letter.pdf', 'fileData' => base64_encode('submitted letter'), 'type' => 'application_letter'],
        ];
        $application = $this->postJson('/api/v2/career/jobs/'.$job.'/apply', [
            'documents' => $documents, 'profileSnapshot' => ['fullName' => 'Asha Mrema', 'resumeFileName' => 'cv.pdf'],
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v2/career/profile', ['details' => ['fullName' => 'Updated profile']])->assertOk();
        $url = '/api/v2/career/jobs/'.$job.'/applications/'.$application;
        $this->actingAs($outsider)->patchJson($url, ['status' => 'hired'])->assertForbidden();
        foreach (['underReview', 'shortlisted', 'interview', 'reserve'] as $status) {
            $this->actingAs($owner)->patchJson($url, ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->actingAs($seeker)->getJson('/api/v2/career/applications')->assertOk()
            ->assertJsonPath('data.0.status', 'reserve')
            ->assertJsonPath('data.0.applicant_snapshot.fullName', 'Asha Mrema')
            ->assertJsonPath('data.0.applicant_snapshot.documents.0.fileData', $documents[0]['fileData']);
        $this->actingAs($owner)->getJson('/api/v2/career/jobs/'.$job.'/applications')->assertOk()
            ->assertJsonPath('data.0.applicant_snapshot.documents.1.fileData', $documents[1]['fileData']);
    }
}
