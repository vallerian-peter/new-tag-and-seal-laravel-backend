<?php

namespace Tests\Feature\Admin;

use App\Models\TransporterCompany;
use App\Models\TransporterVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransporterRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $platformRole = 'admin'): User
    {
        return User::factory()->create(['role' => 'systemUser', 'platform_role' => $platformRole]);
    }

    private function mcuAdmin(string $mcu): User
    {
        $user = $this->admin();
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'mcu_uuid' => $mcu,
            'amcos_uuid' => 'amcos-'.$mcu, 'role' => 'mcu_admin', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function company(string $mcu, string $name, string $status = 'pending'): TransporterCompany
    {
        return TransporterCompany::create([
            'name' => $name, 'mcu_uuid' => $mcu, 'license_number' => 'LIC-'.$name,
            'contact_person' => 'Manager', 'phone' => '0712345678',
            'manager_user_id' => User::factory()->create()->id,
            'approval_status' => $status, 'is_active' => $status === 'approved',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('cooperative_mcus')->insert([
            ['uuid' => 'mcu-a', 'mcu_code' => 'A', 'name' => 'Union A', 'is_active' => true],
            ['uuid' => 'mcu-b', 'mcu_code' => 'B', 'name' => 'Union B', 'is_active' => true],
        ]);
    }

    public function test_mcu_admin_sees_and_decides_only_their_mcu(): void
    {
        $own = $this->company('mcu-a', 'Own');
        $other = $this->company('mcu-b', 'Other', 'approved');
        TransporterVehicle::create(['transporter_company_uuid' => $other->uuid, 'registration_number' => 'T1', 'tanker_capacity_liters' => 5000]);

        Sanctum::actingAs($this->mcuAdmin('mcu-a'));
        $this->getJson('/api/v1/admin/dairy/transport/companies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $own->uuid)
            ->assertJsonPath('data.0.mcu_name', 'Union A')
            ->assertJsonPath('meta.stats.pending', 1)
            ->assertJsonPath('meta.stats.vehicles', 0)
            ->assertJsonPath('meta.from', 1);
        $this->postJson("/api/v1/admin/dairy/transport/companies/{$other->uuid}/approve")->assertForbidden();
        $this->postJson("/api/v1/admin/dairy/transport/companies/{$own->uuid}/reject")->assertUnprocessable();
        $this->postJson("/api/v1/admin/dairy/transport/companies/{$own->uuid}/approve")
            ->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->postJson("/api/v1/admin/dairy/transport/companies/{$own->uuid}/reject", ['remarks' => 'Late'])
            ->assertStatus(409);
        $this->assertDatabaseCount('hiring_notifications', 1);
        $this->assertDatabaseHas('hiring_notifications', ['kind' => 'transporter_approved']);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/dairy/transport/companies')->assertForbidden();
    }

    public function test_super_admin_lists_all_tabs_archives_and_sees_the_fleet(): void
    {
        $pending = $this->company('mcu-a', 'Pending');
        $approved = $this->company('mcu-b', 'Approved', 'approved');
        TransporterVehicle::create(['transporter_company_uuid' => $approved->uuid, 'registration_number' => 'T9', 'tanker_capacity_liters' => 5000]);

        Sanctum::actingAs($this->admin('superAdmin'));
        $this->getJson('/api/v1/admin/dairy/transport/companies?tab=pending')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $pending->uuid);
        $this->getJson('/api/v1/admin/dairy/transport/companies?search=Approved')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.vehicles_count', 1);
        $this->postJson("/api/v1/admin/dairy/transport/companies/{$pending->uuid}/reject", ['remarks' => 'Missing TIN'])
            ->assertOk()->assertJsonPath('data.approval_status', 'rejected');
        $this->assertDatabaseHas('hiring_notifications', ['kind' => 'transporter_rejected']);

        $this->patchJson("/api/v1/admin/dairy/transport/companies/{$approved->uuid}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->deleteJson("/api/v1/admin/dairy/transport/companies/{$approved->uuid}")->assertOk();
        $this->getJson('/api/v1/admin/dairy/transport/companies?tab=archived')
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.stats.total', 1);
        $this->postJson("/api/v1/admin/dairy/transport/companies/{$approved->uuid}/restore")->assertOk();

        $this->getJson("/api/v1/admin/dairy/transport/companies/{$approved->uuid}")
            ->assertJsonPath('data.vehicles.0.registration_number', 'T9');
        $this->getJson('/api/v1/admin/dairy/transport/vehicles')
            ->assertJsonPath('data.0.company_name', 'Renamed')
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/dairy/transport/drivers')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_portal_adds_edits_archives_and_restores_vehicles_and_drivers(): void
    {
        $approved = $this->company('mcu-a', 'Haraka', 'approved');
        $pending = $this->company('mcu-a', 'Waiting');
        $other = $this->company('mcu-b', 'Other', 'approved');
        Sanctum::actingAs($this->mcuAdmin('mcu-a'));

        $vehicle = ['registration_number' => 't 123 abc', 'tanker_capacity_liters' => 5000, 'vehicle_type' => 'TANKER'];
        $this->postJson('/api/v1/admin/dairy/transport/vehicles', [...$vehicle, 'company_uuid' => $pending->uuid])->assertUnprocessable();
        $this->postJson('/api/v1/admin/dairy/transport/vehicles', [...$vehicle, 'company_uuid' => $other->uuid])->assertForbidden();
        $plate = $this->postJson('/api/v1/admin/dairy/transport/vehicles', [...$vehicle, 'company_uuid' => $approved->uuid])
            ->assertCreated()->assertJsonPath('data.registration_number', 'T123ABC')->json('data.uuid');
        $this->postJson('/api/v1/admin/dairy/transport/vehicles', [...$vehicle, 'company_uuid' => $approved->uuid])->assertStatus(409);
        $this->patchJson("/api/v1/admin/dairy/transport/vehicles/{$plate}", ['color' => 'White'])
            ->assertOk()->assertJsonPath('data.color', 'White');

        $driver = [
            'company_uuid' => $approved->uuid, 'first_name' => 'Juma', 'last_name' => 'Ali', 'phone' => '0754000111',
            'licence_number' => 'DL-1', 'licence_class' => 'C',
        ];
        $uuid = $this->postJson('/api/v1/admin/dairy/transport/drivers', $driver)->assertCreated()->json('data.uuid');
        $this->assertDatabaseHas('phone_identities', ['phone' => '+255754000111', 'display_name' => 'Juma Ali', 'pin_pending' => true]);
        $this->postJson('/api/v1/admin/dairy/transport/drivers', $driver)->assertStatus(409);
        $this->patchJson("/api/v1/admin/dairy/transport/drivers/{$uuid}", ['last_name' => 'Hassan'])
            ->assertOk()->assertJsonPath('data.last_name', 'Hassan');
        $this->assertDatabaseHas('phone_identities', ['phone' => '+255754000111', 'display_name' => 'Juma Hassan']);
        DB::table('transporter_drivers')->where('uuid', $uuid)->update(['assigned_vehicle_uuid' => $plate]);

        $this->getJson('/api/v1/admin/dairy/transport/vehicles?sort=alpha-asc')
            ->assertJsonPath('data.0.driver_first_name', 'Juma')
            ->assertJsonPath('meta.stats.assigned', 1)
            ->assertJsonPath('meta.stats.active', 1);
        $this->getJson('/api/v1/admin/dairy/transport/drivers')
            ->assertJsonPath('data.0.assigned_vehicle_plate', 'T123ABC')
            ->assertJsonPath('meta.stats.awaiting_login', 1);

        $this->deleteJson("/api/v1/admin/dairy/transport/vehicles/{$plate}")->assertOk();
        $this->assertDatabaseHas('transporter_drivers', ['uuid' => $uuid, 'assigned_vehicle_uuid' => null]);
        $this->getJson('/api/v1/admin/dairy/transport/vehicles')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/dairy/transport/vehicles?tab=archived')->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/admin/dairy/transport/vehicles/{$plate}/restore")->assertOk();
        $this->deleteJson("/api/v1/admin/dairy/transport/drivers/{$uuid}")->assertOk();
        $this->getJson('/api/v1/admin/dairy/transport/drivers?tab=archived')->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/admin/dairy/transport/drivers/{$uuid}/restore")->assertOk();
        $this->getJson('/api/v1/admin/dairy/transport/drivers')->assertJsonPath('meta.stats.active', 1);
    }
}
