<?php

namespace Tests\Feature\Sync;

use App\Models\Country;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\FarmUser;
use App\Models\FinanceExpense;
use App\Models\User;
use App\Services\Dynamic\FarmAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FarmAccessGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_grant_and_revoke_farm_scoped_access(): void
    {
        $country = Country::create(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = Farmer::create(['farmerNo' => 'F-1', 'firstName' => 'A', 'surname' => 'B', 'phone1' => '0712345678', 'gender' => 'male', 'farmerType' => 'individual', 'countryId' => $country->id, 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'farmer', 'roleId' => $farmer->id]);
        $farm = Farm::create(['farmerId' => $farmer->id, 'uuid' => 'farm-1', 'referenceNo' => 'FARM-1', 'name' => 'Farm', 'size' => 1, 'sizeUnit' => 'acre', 'physicalAddress' => 'Here', 'countryId' => $country->id, 'status' => 'active']);
        $profile = FarmUser::create(['uuid' => (string) Str::uuid(), 'farmUuid' => $farm->uuid, 'firstName' => 'Worker', 'lastName' => 'One', 'gender' => 'male', 'roleTitle' => 'feeding-user', 'status' => 'active']);
        $worker = User::factory()->create(['role' => 'farmInvitedUser', 'roleId' => $profile->id]);
        $outsider = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        DB::table('phone_identities')->insert(['user_id' => $outsider->id, 'phone' => '+255712345600', 'display_name' => 'Verified Worker', 'pin_hash' => 'unused']);
        $this->actingAs($outsider)->getJson('/api/v2/farms/'.$farm->uuid.'/members/search?phone=0712345600')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v2/farms/'.$farm->uuid.'/members/search?phone=0712345600')
            ->assertOk()->assertJsonPath('data.id', $outsider->id)->assertJsonPath('data.name', 'Verified Worker');
        $access = app(FarmAccessService::class);
        $this->assertTrue($access->can($worker, $farm->uuid, 'events.create'));
        $this->assertFalse($access->can($worker, $farm->uuid, 'livestock.manage'));
        $this->actingAs($outsider)->putJson('/api/v2/farms/'.$farm->uuid.'/members/'.$worker->id, ['permissions' => ['farm.view']])->assertForbidden();
        $this->actingAs($owner)->putJson('/api/v2/farms/'.$farm->uuid.'/members/'.$outsider->id, ['permissions' => ['farm.view']])->assertOk();
        $this->actingAs($outsider)->getJson('/api/v1/sync/splash-sync-all/'.$outsider->id)->assertOk()->assertJsonCount(1, 'data.userSpecificData.farms');
        $this->actingAs($owner)->deleteJson('/api/v2/farms/'.$farm->uuid.'/members/'.$outsider->id)->assertOk();
        $this->actingAs($outsider)->getJson('/api/v1/sync/splash-sync-all/'.$outsider->id)->assertOk()->assertJsonCount(0, 'data.userSpecificData.farms');
        $this->actingAs($owner)->putJson('/api/v2/farms/'.$farm->uuid.'/members/'.$worker->id, ['permissions' => ['farm.view', 'livestock.manage']])->assertOk();
        $this->assertTrue($access->can($worker, $farm->uuid, 'livestock.manage'));
        $this->assertFalse($access->can($worker, $farm->uuid, 'events.create'));
        $this->actingAs($owner)->deleteJson('/api/v2/farms/'.$farm->uuid.'/members/'.$worker->id)->assertOk();
        $this->assertFalse($access->canAccess($worker, $farm->uuid));
        $this->actingAs($worker)->getJson('/api/v1/sync/splash-sync-all/'.$worker->id)->assertOk()->assertJsonCount(0, 'data.userSpecificData.farms');
        $this->actingAs($worker)->postJson('/api/v1/dynamic/subjects', [
            'farmUuid' => $farm->uuid, 'blueprintUuid' => (string) Str::uuid(),
            'blueprintVersion' => 1, 'unitType' => 'individual', 'payload' => ['name' => 'Test'],
        ])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'farm.permissions.updated', 'entity_uuid' => $worker->uuid]);
    }

    public function test_farmer_cannot_overwrite_another_farm_via_sync(): void
    {
        $country = Country::create(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $farmer = Farmer::create(['farmerNo' => 'F-1', 'firstName' => 'A', 'surname' => 'B', 'phone1' => '0712345678', 'gender' => 'male', 'farmerType' => 'individual', 'countryId' => $country->id, 'status' => 'active']);
        $other = Farmer::create(['farmerNo' => 'F-2', 'firstName' => 'C', 'surname' => 'D', 'phone1' => '0712345679', 'gender' => 'male', 'farmerType' => 'individual', 'countryId' => $country->id, 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'farmer', 'roleId' => $farmer->id]);
        $farm = Farm::create(['farmerId' => $other->id, 'uuid' => 'other-farm', 'referenceNo' => 'OTHER', 'name' => 'Original', 'size' => 1, 'sizeUnit' => 'acre', 'physicalAddress' => 'Here', 'countryId' => $country->id, 'status' => 'active']);
        $this->actingAs($owner)->postJson('/api/v1/sync/full-post-sync/'.$owner->id, ['farms' => [['uuid' => $farm->uuid, 'name' => 'Changed', 'syncAction' => 'create']]])->assertOk()->assertJsonCount(0, 'data.syncedFarms');
        $this->assertDatabaseHas('farms', ['uuid' => $farm->uuid, 'farmerId' => $other->id, 'name' => 'Original']);
        $ownedFarm = Farm::create(['farmerId' => $farmer->id, 'uuid' => 'own-farm', 'referenceNo' => 'OWN', 'name' => 'Own', 'size' => 1, 'sizeUnit' => 'acre', 'physicalAddress' => 'Here', 'countryId' => $country->id, 'status' => 'active']);
        FinanceExpense::create(['uuid' => 'expense-other', 'sourceType' => 'manual', 'sourceUuid' => 'expense-other', 'farmUuid' => $farm->uuid, 'farmerId' => $other->id, 'subjectType' => 'Other', 'quantity' => 1, 'unitCost' => 10, 'totalCost' => 10, 'status' => 'paid', 'expenseDate' => now()]);
        $this->actingAs($owner)->postJson('/api/v1/sync/full-post-sync/'.$owner->id, ['financeExpenses' => [['uuid' => 'expense-other', 'farmUuid' => $ownedFarm->uuid, 'syncAction' => 'deleted']]])
            ->assertOk()->assertJsonCount(0, 'data.syncedFinanceExpenses');
        $this->assertDatabaseHas('finance_expenses', ['uuid' => 'expense-other', 'farmUuid' => $farm->uuid]);
    }
}
