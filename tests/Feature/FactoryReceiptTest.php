<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FactoryReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $manager;

    private User $driver;

    private array $handover;

    protected function setUp(): void
    {
        parent::setUp();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Mbeya', 'shortName' => 'MB', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'Rungwe', 'regionId' => $region]);
        DB::table('cooperative_collection_centres')->insert([
            ['uuid' => 'centre-1', 'amcos_uuid' => 'amcos-1', 'name' => 'Kiwira Centre', 'centre_code' => 'C1'],
            ['uuid' => 'centre-2', 'amcos_uuid' => 'amcos-1', 'name' => 'Tukuyu Centre', 'centre_code' => 'C2'],
        ]);
        foreach (['factory-1' => 'Mbeya Dairy', 'factory-2' => 'Njombe Dairy'] as $uuid => $name) {
            DB::table('dairy_factories')->insert([
                'uuid' => $uuid, 'name' => $name, 'factory_code' => strtoupper($uuid), 'registration_number' => "REG-{$uuid}",
                'contact_name' => 'Plant', 'contact_phone' => '0700000000', 'region_id' => $region, 'district_id' => $district,
                'approval_status' => 'approved', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->operator = User::factory()->create();
        $this->manager = User::factory()->create();
        foreach ([[$this->operator, 'operator'], [$this->manager, 'manager']] as [$user, $role]) {
            DB::table('factory_operators')->insert([
                'uuid' => (string) Str::uuid(), 'factory_uuid' => 'factory-1', 'user_id' => $user->id,
                'first_name' => ucfirst($role), 'role' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->handover = ['welcoming_operator_uuid' => DB::table('factory_operators')
            ->where('user_id', $this->operator->id)->value('uuid')];
        $driver = $this->driver = User::factory()->create();
        DB::table('dairy_trips')->insert([
            'uuid' => 'trip-1', 'trip_number' => 'TRIP-1', 'transporter_company_uuid' => 'company-1',
            'driver_user_id' => $driver->id, 'vehicle_uuid' => 'vehicle-1', 'vehicle_plate_number' => 'T 123 ABC',
            'destination_factory_uuid' => 'factory-1', 'status' => 'in_transit', 'approval_status' => 'approved',
            'phase' => 'IN_TRANSIT', 'total_liters' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['disp-1' => ['centre-1', 300], 'disp-2' => ['centre-2', 200]] as $uuid => [$centre, $litres]) {
            DB::table('cooperative_dispatches')->insert([
                'uuid' => $uuid, 'dispatch_code' => strtoupper($uuid), 'batch_number' => "B-{$uuid}", 'centre_uuid' => $centre,
                'quantity' => $litres, 'unit' => 'l', 'destination_uuid' => 'factory-1', 'destination_name' => 'Mbeya Dairy',
                'driver_user_id' => $driver->id, 'status' => 'on_trip', 'trip_uuid' => 'trip-1', 'dispatched_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_portal_reviews_app_handovers_with_factory_manager_permissions_and_snapshot_protection(): void
    {
        Sanctum::actingAs($this->driver);
        $this->postJson('/api/v2/dairy/driver/trips/trip-1/arrive', $this->handover)->assertOk();
        Sanctum::actingAs($this->operator);
        $receiptIds = [];
        foreach (['disp-1' => 300, 'disp-2' => 200] as $dispatch => $quantity) {
            $receiptIds[] = $id = (string) Str::uuid();
            $this->postJson('/api/v2/dairy/factory/receipts', [
                'uuid' => $id, 'dispatch_uuid' => $dispatch, 'action' => 'receive',
                'received_volume_liters' => $quantity, 'arrival_temperature_celsius' => 4,
            ])->assertCreated();
        }
        foreach ([$this->operator, $this->manager] as $user) {
            $user->forceFill(['platform_role' => 'admin'])->save();
            foreach (['dairy.view', 'dairy.manage'] as $permission) {
                DB::table('user_platform_permissions')->insert([
                    'user_uuid' => $user->uuid, 'permission_id' => DB::table('platform_permissions')->where('code', $permission)->value('id'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $base = '/api/v1/admin/dairy/factories/factory-1/handovers';
        Sanctum::actingAs($this->operator->fresh());
        $this->getJson($base)->assertOk()->assertJsonPath('meta.canApprove', false)->assertJsonPath('data.0.awaiting_approval', 2);
        $body = ['receipt_uuids' => $receiptIds, 'decision' => 'approved'];
        $this->postJson($base.'/trip-1/decision', $body)->assertForbidden();
        Sanctum::actingAs($this->manager->fresh());
        $this->getJson($base.'?search=T%20123&sort=asc&per_page=10')->assertOk()->assertJsonPath('meta.canApprove', true)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.received_count', 2);
        $this->getJson('/api/v1/admin/dairy/factories/factory-2/handovers')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/admin/dairy/factories/factory-2/handovers/trip-1/decision', $body)->assertNotFound();
        $this->postJson($base.'/trip-1/decision', [...$body, 'receipt_uuids' => [$receiptIds[0]]])->assertStatus(409);
        $this->postJson($base.'/trip-1/decision', [...$body, 'decision' => 'rejected'])->assertUnprocessable();
        DB::table('factory_operators')->where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->postJson($base.'/trip-1/decision', $body)->assertForbidden();
        DB::table('factory_operators')->where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->postJson($base.'/trip-1/decision', $body)->assertOk()->assertJsonPath('data.phase', 'COMPLETED');
        $this->postJson($base.'/trip-1/decision', $body)->assertStatus(409);
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($base.'?status=approved')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('factory_dispatch_receipts', ['uuid' => $receiptIds[0], 'status' => 'approved', 'decided_by_user_id' => $this->manager->id]);
        $this->assertDatabaseHas('milk_factory_deliveries', ['trip_uuid' => 'trip-1', 'accepted_volume_liters' => 500]);
        Sanctum::actingAs($this->manager->fresh());
        $this->getJson('/api/v2/dairy/factory/trips')->assertOk()->assertJsonPath('data.trips.0.awaiting_approval', 0);
    }

    public function test_super_admin_and_the_factorys_mcu_admin_can_decide_portal_handovers(): void
    {
        DB::table('dairy_factories')->where('uuid', 'factory-1')->update(['mcu_uuid' => 'mcu-1']);
        Sanctum::actingAs($this->driver);
        $this->postJson('/api/v2/dairy/driver/trips/trip-1/arrive', $this->handover)->assertOk();
        Sanctum::actingAs($this->operator);
        $receiptIds = [];
        foreach (['disp-1' => 300, 'disp-2' => 200] as $dispatch => $quantity) {
            $receiptIds[] = $id = (string) Str::uuid();
            $this->postJson('/api/v2/dairy/factory/receipts', [
                'uuid' => $id, 'dispatch_uuid' => $dispatch, 'action' => 'receive', 'received_volume_liters' => $quantity,
            ])->assertCreated();
        }
        $mcuAdmin = $this->portalViewer('mcu-1');
        $otherMcuAdmin = $this->portalViewer('mcu-2');
        $base = '/api/v1/admin/dairy/factories/factory-1/handovers';
        $body = ['receipt_uuids' => $receiptIds, 'decision' => 'rejected', 'notes' => 'Sour milk'];

        Sanctum::actingAs(User::factory()->create(['platform_role' => 'superAdmin']));
        $this->getJson($base)->assertOk()->assertJsonPath('meta.canApprove', true);
        Sanctum::actingAs($otherMcuAdmin);
        $this->getJson($base)->assertOk()->assertJsonPath('meta.canApprove', false);
        $this->postJson($base.'/trip-1/decision', $body)->assertForbidden();
        Sanctum::actingAs($mcuAdmin);
        $this->getJson($base)->assertOk()->assertJsonPath('meta.canApprove', true);
        $this->postJson($base.'/trip-1/decision', [...$body, 'notes' => null])->assertUnprocessable();
        $this->postJson($base.'/trip-1/decision', $body)->assertOk();
        $this->assertDatabaseHas('factory_dispatch_receipts', ['uuid' => $receiptIds[0], 'status' => 'rejected', 'decided_by_user_id' => $mcuAdmin->id, 'decision_notes' => 'Sour milk']);
    }

    private function portalViewer(string $mcu): User
    {
        $user = User::factory()->create(['platform_role' => 'admin']);
        DB::table('user_platform_permissions')->insert([
            'user_uuid' => $user->uuid, 'permission_id' => DB::table('platform_permissions')->where('code', 'dairy.view')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'amcos_uuid' => 'amcos-1', 'mcu_uuid' => $mcu, 'role' => 'mcu_admin', 'status' => 'approved',
        ]);

        return $user->fresh();
    }

    public function test_operator_receives_each_dispatch_and_manager_approval_completes_the_trip(): void
    {
        Sanctum::actingAs($this->operator);
        $data = $this->getJson('/api/v2/dairy/factory/trips')->assertOk()->json('data');
        $this->assertSame('operator', $data['role']);
        $this->assertSame('IN_TRANSIT', $data['trips'][0]['phase']);
        $this->assertSame(2, $data['trips'][0]['dispatch_count']);

        $first = (string) Str::uuid();
        $body = ['uuid' => $first, 'dispatch_uuid' => 'disp-1', 'action' => 'receive', 'received_volume_liters' => 300,
            'arrival_temperature_celsius' => 4.2, 'seals_intact' => true, 'quality' => ['fat_percentage' => 3.8, 'alcohol_test_passed' => true]];
        $this->postJson('/api/v2/dairy/factory/receipts', $body)->assertUnprocessable();
        $this->postJson('/api/v2/dairy/factory/trips/trip-1/arrive')->assertNotFound();
        $this->assertDatabaseHas('dairy_trips', ['uuid' => 'trip-1', 'status' => 'in_transit']);
        Sanctum::actingAs($this->driver);
        $members = $this->getJson('/api/v2/dairy/driver/trips')->assertOk()->json('data.0.factory_members');
        $this->assertEqualsCanonicalizing(['operator', 'manager'], array_column($members, 'role'));
        $this->postJson('/api/v2/dairy/driver/trips/trip-1/arrive')->assertUnprocessable();
        $other = (string) Str::uuid();
        DB::table('factory_operators')->insert([
            'uuid' => $other, 'factory_uuid' => 'factory-2', 'user_id' => User::factory()->create()->id,
            'role' => 'operator', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v2/dairy/driver/trips/trip-1/arrive', ['welcoming_operator_uuid' => $other])
            ->assertUnprocessable();
        $this->postJson('/api/v2/dairy/driver/trips/trip-1/arrive', [...$this->handover, 'notes' => 'Gate 2'])->assertOk();
        $this->assertDatabaseHas('dairy_trips', [
            'uuid' => 'trip-1', 'arrival_notes' => 'Gate 2', ...$this->handover,
        ]);
        $this->assertNotNull($this->getJson('/api/v2/dairy/driver/trips')->json('data.0.welcoming_operator_name'));
        Sanctum::actingAs($this->operator);
        $this->assertSame('ARRIVED', $this->getJson('/api/v2/dairy/factory/trips')->json('data.trips.0.phase'));
        $this->assertDatabaseHas('dairy_trips', ['uuid' => 'trip-1', 'status' => 'arrived', 'phase' => 'ARRIVED']);
        $this->assertDatabaseHas('cooperative_dispatches', ['uuid' => 'disp-2', 'status' => 'delivered']);
        $this->postJson('/api/v2/dairy/factory/receipts', $body)->assertCreated();
        $this->postJson('/api/v2/dairy/factory/receipts', $body)->assertOk();
        $this->postJson('/api/v2/dairy/factory/receipts', [...$body, 'uuid' => (string) Str::uuid()])->assertStatus(409);

        $this->postJson('/api/v2/dairy/factory/receipts', ['uuid' => (string) Str::uuid(), 'dispatch_uuid' => 'disp-2',
            'action' => 'receive', 'received_volume_liters' => 190])->assertUnprocessable();
        $this->postJson('/api/v2/dairy/factory/receipts', ['uuid' => (string) Str::uuid(), 'dispatch_uuid' => 'disp-2',
            'action' => 'receive', 'received_volume_liters' => 190, 'reason' => 'Spillage'])->assertCreated();

        $trip = $this->getJson('/api/v2/dairy/factory/trips')->json('data.trips.0');
        $this->assertSame(2, $trip['awaiting_approval']);
        $this->assertEquals(3.8, $trip['dispatches'][0]['receipt']['quality']['fat_percentage']);
        $this->assertTrue($trip['can_complete']);
        $this->postJson('/api/v2/dairy/factory/trips/trip-1/decision', ['decision' => 'approved'])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->postJson('/api/v2/dairy/factory/trips/trip-1/decision', ['decision' => 'rejected'])->assertUnprocessable();
        $done = $this->postJson('/api/v2/dairy/factory/trips/trip-1/decision', ['decision' => 'approved'])
            ->assertOk()->json('data');
        $this->assertSame('COMPLETED', $done['phase']);
        $this->assertSame(0, $done['awaiting_approval']);
        $this->assertDatabaseHas('cooperative_dispatches', ['uuid' => 'disp-1', 'status' => 'reconciled']);
        $delivery = DB::table('milk_factory_deliveries')->where('trip_uuid', 'trip-1')->first();
        $this->assertEquals(490, $delivery->accepted_volume_liters);
        $this->assertEquals(-10, $delivery->discrepancy_liters);
        $this->assertSame('accepted_with_deduction', $delivery->factory_qa_status);
    }

    public function test_rejected_dispatch_and_other_factories_are_refused(): void
    {
        Sanctum::actingAs($this->operator);
        DB::table('dairy_trips')->where('uuid', 'trip-1')->update(['status' => 'approved']);
        $this->postJson('/api/v2/dairy/factory/trips/trip-1/arrive')->assertNotFound();
        DB::table('dairy_trips')->where('uuid', 'trip-1')->update(['status' => 'arrived']);
        $this->postJson('/api/v2/dairy/factory/receipts', ['uuid' => (string) Str::uuid(), 'dispatch_uuid' => 'disp-1',
            'action' => 'reject'])->assertUnprocessable();
        $this->postJson('/api/v2/dairy/factory/receipts', ['uuid' => (string) Str::uuid(), 'dispatch_uuid' => 'disp-1',
            'action' => 'reject', 'reason' => 'Sour milk'])->assertCreated();
        $this->assertDatabaseHas('factory_dispatch_receipts', ['dispatch_uuid' => 'disp-1', 'accepted_volume_liters' => 0,
            'qa_status' => 'rejected_spoiled']);

        DB::table('dairy_trips')->where('uuid', 'trip-1')->update(['destination_factory_uuid' => 'factory-2']);
        $this->postJson('/api/v2/dairy/factory/receipts', ['uuid' => (string) Str::uuid(), 'dispatch_uuid' => 'disp-2',
            'action' => 'receive', 'received_volume_liters' => 200])->assertNotFound();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v2/dairy/factory/trips')->assertForbidden();
    }
}
