<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DairyPhaseOneRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_dairy_routes_require_authentication(): void
    {
        foreach ([
            ['GET', 'hierarchy'],
            ['POST', 'organizations'],
            ['POST', 'amcos-membership'],
            ['POST', 'centres'],
            ['POST', 'seasons'],
            ['GET', 'farmers'],
            ['POST', 'farmers/request-otp'],
            ['POST', 'farmers/enroll'],
            ['POST', 'farmers/verify-otp'],
            ['GET', 'intakes'],
            ['POST', 'intakes'],
            ['POST', 'centre-stock/movement'],
            ['GET', 'ledger'],
            ['POST', 'ledger/entry'],
        ] as [$method, $path]) {
            $this->json($method, "/api/v2/dairy/{$path}")->assertUnauthorized();
        }
    }

    public function test_dairy_writes_still_validate_required_fields(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([
            'organizations',
            'amcos-membership',
            'centres',
            'seasons',
            'farmers/request-otp',
            'farmers/enroll',
            'farmers/verify-otp',
            'intakes',
            'centre-stock/movement',
            'ledger/entry',
        ] as $path) {
            $this->postJson("/api/v2/dairy/{$path}", [])->assertUnprocessable();
        }
    }

    public function test_authenticated_reads_keep_their_response_shapes(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v2/dairy/hierarchy')
            ->assertOk()->assertJsonStructure([
                'status', 'data' => ['units', 'mcus', 'amcos', 'centres', 'seasons', 'my_staff_memberships', 'my_farmer_memberships'],
            ]);
        $this->getJson('/api/v2/dairy/farmers')
            ->assertForbidden();
        $this->getJson('/api/v2/dairy/ledger')
            ->assertOk()->assertJsonStructure(['data' => ['summary', 'entries']]);
    }

    public function test_drivers_are_named_from_their_phone_identity(): void
    {
        $manager = User::factory()->create();
        $driver = User::factory()->create(['username' => 'driver.user']);
        DB::table('phone_identities')->insert([
            'user_id' => $driver->id,
            'phone' => '+255700000001',
            'display_name' => 'Juma Driver',
            'pin_hash' => bcrypt('1234'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transporter_companies')->insert([
            'uuid' => 'tc-1',
            'name' => 'Fast Haulage',
            'license_number' => 'TL-1',
            'contact_person' => 'Owner',
            'phone' => '+255700000009',
            'manager_user_id' => $manager->id,
            'approval_status' => 'approved',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transporter_drivers')->insert([
            'uuid' => 'td-1',
            'transporter_company_uuid' => 'tc-1',
            'user_id' => $driver->id,
            'driver_license_number' => 'DL-1',
            'approval_status' => 'approved',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager);
        $this->getJson('/api/v2/dairy/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.drivers.0.name', 'Juma Driver')
            ->assertJsonPath('data.drivers.0.phone', '+255700000001');
        $this->getJson('/api/v2/dairy/transporter/fleet')
            ->assertOk()
            ->assertJsonPath('data.drivers.0.name', 'Juma Driver');
    }
}
