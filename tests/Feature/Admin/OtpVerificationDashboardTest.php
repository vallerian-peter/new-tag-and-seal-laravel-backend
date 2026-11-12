<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\PhoneAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OtpVerificationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['platform_role' => 'superAdmin']);
    }

    private function challenge(array $attributes = []): int
    {
        return DB::table('phone_auth_challenges')->insertGetId(array_merge([
            'phone' => '+255712345678', 'purpose' => 'register', 'code_hash' => 'secret-code-hash',
            'proof_hash' => 'secret-proof', 'attempts' => 0, 'expires_at' => now()->addMinutes(5),
            'verified_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    public function test_combined_records_have_accurate_stats_filters_and_no_secrets(): void
    {
        $this->challenge();
        $this->challenge(['phone' => '+255712345679', 'attempts' => 5]);
        $this->challenge(['phone' => '+255712345680', 'verified_at' => now(), 'expires_at' => now()->subMinute()]);
        DB::table('otp_verifications')->insert([
            'email' => 'test@example.com', 'otp' => '123456', 'verified' => false,
            'expires_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->actingAs($this->admin())->getJson('/api/v1/admin/otp-verifications')
            ->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('stats', ['total' => 4, 'pending' => 1, 'verified' => 1, 'expired' => 1, 'locked' => 1]);
        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('otp', $row);
            $this->assertArrayNotHasKey('code_hash', $row);
            $this->assertArrayNotHasKey('proof_hash', $row);
        }
        $this->getJson('/api/v1/admin/otp-verifications?status=expired&channel=email&search=test%40example.com')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('stats.total', 4);
        $this->getJson('/api/v1/admin/otp-verifications?status=locked')->assertOk()->assertJsonPath('data.0.attempts', 5);
        $this->getJson('/api/v1/admin/otp-verifications?per_page=999')->assertUnprocessable();
    }

    public function test_otp_permissions_distinguish_read_and_manage_access(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->getJson('/api/v1/admin/otp-verifications')->assertForbidden();
        $permission = DB::table('platform_permissions')->where('code', 'accounts.view')->value('id');
        DB::table('user_platform_permissions')->insert([
            'user_uuid' => $admin->uuid, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/admin/otp-verifications')->assertOk()->assertJsonPath('canManage', false);
        $this->postJson('/api/v1/admin/otp-verifications', [])->assertForbidden();
        $this->deleteJson('/api/v1/admin/otp-verifications/phone/1')->assertForbidden();
    }

    public function test_create_uses_phone_delivery_and_cooldown_without_returning_code(): void
    {
        $gateway = new class extends PhoneAuthentication
        {
            public string $sentPhone = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->sentPhone = $phone;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $this->actingAs($this->admin())->postJson('/api/v1/admin/otp-verifications', ['phone' => '0712345678', 'purpose' => 'register'])
            ->assertCreated()->assertExactJson(['message' => 'OTP created and sent successfully.']);
        $this->assertSame('+255712345678', $gateway->sentPhone);
        $this->assertDatabaseHas('phone_auth_challenges', ['phone' => '+255712345678', 'purpose' => 'register']);
        $this->postJson('/api/v1/admin/otp-verifications', ['phone' => '0712345678', 'purpose' => 'register'])->assertStatus(429);
    }

    public function test_failed_delivery_does_not_leave_a_pending_otp(): void
    {
        $gateway = new class extends PhoneAuthentication
        {
            public function sendCode(string $phone, string $code): bool
            {
                return false;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $this->actingAs($this->admin())->postJson('/api/v1/admin/otp-verifications', [
            'phone' => '0712345678', 'purpose' => 'register',
        ])->assertStatus(503)->assertJsonPath('code', 'OTP_DELIVERY_FAILED');
        $this->assertDatabaseMissing('phone_auth_challenges', ['phone' => '+255712345678']);
    }

    public function test_records_are_paginated_without_changing_global_stats(): void
    {
        for ($index = 0; $index < 11; $index++) {
            $this->challenge(['phone' => '+2557123456'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
        }
        $this->actingAs($this->admin())->getJson('/api/v1/admin/otp-verifications?per_page=10&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 11)
            ->assertJsonPath('meta.currentPage', 2)->assertJsonPath('stats.pending', 11);
    }

    public function test_delete_targets_only_the_selected_source_and_invalidates_verification(): void
    {
        $id = $this->challenge();
        DB::table('otp_verifications')->insert([
            'id' => $id, 'phone' => '+255712345678', 'otp' => '123456', 'verified' => false,
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin())->deleteJson('/api/v1/admin/otp-verifications/phone/'.$id)->assertOk();
        $this->assertDatabaseMissing('phone_auth_challenges', ['id' => $id]);
        $this->assertDatabaseHas('otp_verifications', ['id' => $id]);
        $this->deleteJson('/api/v1/admin/otp-verifications/legacy/'.$id)->assertOk();
        $this->deleteJson('/api/v1/admin/otp-verifications/legacy/'.$id)->assertNotFound();
        $this->deleteJson('/api/v1/admin/otp-verifications/users/1')->assertNotFound();
    }
}
