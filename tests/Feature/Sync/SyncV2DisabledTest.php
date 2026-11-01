<?php

namespace Tests\Feature\Sync;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Farmer;
use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncV2DisabledTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $profile = SystemUser::create([
            'firstName' => 'Sync',
            'lastName' => 'Tester',
            'phone' => '+255711000000',
            'address' => 'Arusha HQ',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Sync Tester',
            'username' => 'sync_tester',
            'phone' => '+255711000000',
            'email' => 'sync_tester@tagandseal.tz',
            'password' => Hash::make('Secret123!'),
            'role' => UserRole::SYSTEM_USER,
            'roleId' => $profile->id,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_post_sync_rejects_nonempty_v2_sections(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        foreach ([
            'dairyMcc' => ['intakes' => [['uuid' => (string) Str::uuid()]]],
            'dairyLogistics' => ['trips' => [['uuid' => (string) Str::uuid()]]],
            'marketplace' => ['listings' => [['uuid' => (string) Str::uuid()]]],
        ] as $section => $records) {
            $this->postJson("/api/v1/sync/full-post-sync/{$this->user->id}", [$section => $records])
                ->assertStatus(422)
                ->assertJsonPath('status', false);
        }

    }

    public function test_core_post_sync_accepts_empty_legacy_v2_sections(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->postJson("/api/v1/sync/full-post-sync/{$this->user->id}", [
            'dairyMcc' => ['intakes' => []],
            'dairyLogistics' => ['trips' => [], 'routes' => []],
            'marketplace' => ['listings' => [], 'offers' => []],
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.syncedDairyMcc');
        $response->assertJsonMissingPath('data.syncedDairyLogistics');
        $response->assertJsonMissingPath('data.syncedMarketplace');
        $this->assertNotContains('step_10_dairy_mcc', $response->json('step_by_step_execution'));
    }

    public function test_unauthenticated_sync_is_rejected(): void
    {
        $this->assertContains(
            $this->postJson("/api/v1/sync/full-post-sync/{$this->user->id}", [])->status(),
            [401, 403],
        );
        $this->assertContains(
            $this->getJson("/api/v1/sync/splash-sync-all/{$this->user->id}")->status(),
            [401, 403],
        );
    }

    public function test_mismatched_user_id_is_rejected(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        $otherUserId = $this->user->id + 1;

        $this->postJson("/api/v1/sync/full-post-sync/{$otherUserId}", [])->assertStatus(403);
        $this->getJson("/api/v1/sync/splash-sync-all/{$otherUserId}")->assertStatus(403);
    }

    public function test_farmer_splash_sync_omits_v2_data(): void
    {
        $country = Country::create(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = Farmer::create([
            'farmerNo' => 'FRM-TEST-001',
            'firstName' => 'John',
            'surname' => 'Doe',
            'phone1' => '+255711223344',
            'gender' => 'male',
            'farmerType' => 'individual',
            'status' => 'active',
            'countryId' => $country->id,
        ]);
        $farmerUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'John Doe',
            'username' => 'farmer_john',
            'phone' => '+255711223344',
            'email' => 'john.farmer@test.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'farmer',
            'roleId' => $farmer->id,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($farmerUser);

        $response = $this->getJson("/api/v1/sync/splash-sync-all/{$farmerUser->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('data.userSpecificData.type', 'farmer');
        $response->assertJsonStructure(['data' => ['locations', 'referenceData', 'livestockReferenceData', 'userSpecificData' => ['farms', 'livestock']]]);
        $response->assertJsonMissingPath('data.userSpecificData.milkIntakes');
        $response->assertJsonMissingPath('data.userSpecificData.marketplaceListings');
        $response->assertJsonMissingPath('data.tombstones');
    }
}
