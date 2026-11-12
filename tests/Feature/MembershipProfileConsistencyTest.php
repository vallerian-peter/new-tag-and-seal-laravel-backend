<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipProfileConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeker_registration_and_profile_share_name_without_losing_cv(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $user = User::factory()->create();
        $details = ['name' => 'Original Name', 'phone' => '0712345678', 'countryId' => $country, 'skills' => ['Dairy']];
        $this->actingAs($user)->postJson('/api/v2/memberships', ['feature' => 'jobSeeker', 'details' => $details])->assertOk();
        $this->postJson('/api/v2/career/profile', ['details' => [
            'fullName' => 'Updated Profile', 'email' => 'test@example.com',
            'qualifications' => [['id' => 'q1', 'field' => 'Dairy']],
        ]])->assertOk()->assertJsonPath('data.name', 'Updated Profile');
        $this->getJson('/api/v2/memberships')->assertJsonPath('data.0.details.name', 'Updated Profile');
        $this->postJson('/api/v2/memberships', [
            'feature' => 'jobSeeker', 'details' => [...$details, 'name' => 'Updated Registration'],
        ])->assertOk();
        $this->getJson('/api/v2/career/profile')->assertOk()
            ->assertJsonPath('data.fullName', 'Updated Registration')
            ->assertJsonPath('data.email', 'test@example.com')
            ->assertJsonPath('data.qualifications.0.id', 'q1');
    }

    public function test_farmer_registration_updates_only_the_linked_owned_profile(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $owner = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $other = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $details = ['name' => 'Original Farmer', 'phone' => '0712345678', 'countryId' => $country, 'gender' => 'female'];

        $this->actingAs($owner)->postJson('/api/v2/memberships', [
            'feature' => 'farmer', 'details' => $details,
        ])->assertOk()->assertJsonPath('data.0.farmerProfile.firstName', 'Original');
        $farmerId = DB::table('feature_memberships')->where('user_id', $owner->id)->value('farmer_id');
        DB::table('farmers')->where('id', $farmerId)->update(['email' => 'preserved@example.com']);

        $this->actingAs($other)->postJson('/api/v2/memberships', [
            'feature' => 'farmer', 'details' => [...$details, 'name' => 'Not Owner'],
        ])->assertStatus(409);
        $this->assertDatabaseHas('farmers', ['id' => $farmerId, 'firstName' => 'Original']);
        $this->assertDatabaseMissing('feature_memberships', ['user_id' => $other->id, 'feature' => 'farmer']);

        $this->actingAs($owner)->postJson('/api/v2/memberships', [
            'feature' => 'farmer',
            'details' => [...$details, 'name' => 'Corrected Name', 'phone' => '0712345679'],
        ])->assertOk()
            ->assertJsonPath('data.0.farmerProfile.firstName', 'Corrected')
            ->assertJsonPath('data.0.farmerProfile.surname', 'Name')
            ->assertJsonPath('data.0.farmerProfile.phone1', '0712345679')
            ->assertJsonPath('data.0.farmerProfile.email', 'preserved@example.com');
        $this->assertSame(1, DB::table('farmers')->count());
        $this->actingAs($owner)->getJson('/api/v2/memberships')
            ->assertOk()->assertJsonPath('data.0.farmerProfile.firstName', 'Corrected');
    }

    public function test_structured_membership_name_parts_update_farmer_and_phone_profiles(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $user = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        DB::table('phone_identities')->insert([
            'user_id' => $user->id,
            'phone' => '0712345678',
            'display_name' => 'Previous Name',
            'pin_hash' => 'test-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v2/memberships', [
            'feature' => 'farmer',
            'details' => [
                'name' => 'Amina Rehema Farmer',
                'firstName' => 'Amina',
                'middleName' => 'Rehema',
                'surname' => 'Farmer',
                'phone' => '0712345678',
                'countryId' => $country,
                'gender' => 'female',
            ],
        ])->assertOk()
            ->assertJsonPath('data.0.details.name', 'Amina Rehema Farmer')
            ->assertJsonPath('data.0.details.firstName', 'Amina')
            ->assertJsonPath('data.0.farmerProfile.firstName', 'Amina')
            ->assertJsonPath('data.0.farmerProfile.middleName', 'Rehema')
            ->assertJsonPath('data.0.farmerProfile.surname', 'Farmer');

        $this->assertDatabaseHas('phone_identities', [
            'user_id' => $user->id,
            'display_name' => 'Amina Rehema Farmer',
        ]);
        $this->assertDatabaseHas('farmers', [
            'id' => $response->json('data.0.farmerProfile.id'),
            'middleName' => 'Rehema',
        ]);
    }
}
