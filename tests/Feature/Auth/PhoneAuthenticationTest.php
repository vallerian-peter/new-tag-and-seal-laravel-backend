<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\PhoneAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_registration_and_pin_login(): void
    {
        $gateway = new class extends PhoneAuthentication
        {
            public string $code = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->code = $code;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $this->postJson('/api/auth/phone/check', ['phone' => '0712345678'])->assertOk()->assertJsonPath('next', 'otp');
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0712345678', 'purpose' => 'register'])->assertOk();
        $this->assertNotSame($gateway->code, DB::table('phone_auth_challenges')->value('code_hash'));
        $wrongCode = $gateway->code === '999999' ? '000000' : '999999';
        $this->postJson('/api/auth/phone/verify-otp', ['phone' => '+255712345678', 'otp' => $wrongCode])->assertUnprocessable();
        $verification = $this->postJson('/api/auth/phone/verify-otp', ['phone' => '0712345678', 'otp' => $gateway->code])->assertOk()->json('verificationToken');
        $data = ['phone' => '0712345678', 'verificationToken' => $verification, 'name' => 'Test User', 'pin' => '2345', 'pin_confirmation' => '2345'];
        $this->postJson('/api/auth/phone/complete', $data)->assertOk()->assertJsonPath('data.user.firstname', 'Test')->assertJsonPath('data.user.surname', 'User');
        $this->postJson('/api/auth/phone/complete', $data)->assertUnprocessable();
        $this->postJson('/api/auth/phone/check', ['phone' => '+255712345678'])->assertOk()->assertJsonPath('next', 'pin');
        $this->postJson('/api/auth/phone/login', ['phone' => '0712345678', 'pin' => '9999'])->assertUnauthorized();
        $this->postJson('/api/auth/phone/login', ['phone' => '0712345678', 'pin' => '2345'])
            ->assertOk()
            ->assertJsonPath('data.user.firstname', 'Test')->assertJsonPath('data.user.surname', 'User')
            ->assertJsonStructure([
                'data' => [
                    'memberships' => [
                        'features',
                        'cooperativeStaff',
                        'cooperativeFarmers',
                        'cooperativeStaffEntry',
                        'transportation',
                        'factories',
                        'farmAccess',
                    ],
                ],
            ]);
    }

    public function test_account_created_for_a_phone_sets_its_first_pin_through_otp(): void
    {
        $gateway = new class extends PhoneAuthentication
        {
            public string $code = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->code = $code;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $user = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        DB::table('phone_identities')->insert([
            'user_id' => $user->id, 'phone' => '+255712345678', 'display_name' => 'Asha Juma',
            'pin_hash' => bcrypt('random'), 'pin_pending' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/phone/check', ['phone' => '0712345678'])->assertOk()->assertJsonPath('next', 'setup');
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0712345678', 'purpose' => 'reset'])->assertOk();
        $verification = $this->postJson('/api/auth/phone/verify-otp', ['phone' => '0712345678', 'otp' => $gateway->code])->json('verificationToken');
        $this->postJson('/api/auth/phone/complete', [
            'phone' => '0712345678', 'verificationToken' => $verification, 'pin' => '2345', 'pin_confirmation' => '2345',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('phone_identities', ['user_id' => $user->id, 'display_name' => 'Asha Juma', 'pin_pending' => false]);
        $this->assertSame(1, DB::table('users')->count());
        $this->postJson('/api/auth/phone/check', ['phone' => '0712345678'])->assertJsonPath('next', 'pin');
        $this->postJson('/api/auth/phone/login', ['phone' => '0712345678', 'pin' => '2345'])->assertOk();
        $this->travel(2)->minutes();
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0712345678', 'purpose' => 'register'])
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_AUTH_FLOW');
    }

    public function test_older_apps_can_set_the_first_pin_with_the_register_flow(): void
    {
        $gateway = new class extends PhoneAuthentication
        {
            public string $code = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->code = $code;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $user = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        DB::table('phone_identities')->insert([
            'user_id' => $user->id, 'phone' => '+255712345678', 'display_name' => 'Asha Juma',
            'pin_hash' => bcrypt('random'), 'pin_pending' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0712345678', 'purpose' => 'register'])->assertOk();
        $verification = $this->postJson('/api/auth/phone/verify-otp', ['phone' => '0712345678', 'otp' => $gateway->code])->json('verificationToken');
        $this->postJson('/api/auth/phone/complete', [
            'phone' => '0712345678', 'verificationToken' => $verification, 'name' => 'Asha Juma', 'pin' => '2345', 'pin_confirmation' => '2345',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_failed_sms_does_not_leave_a_usable_challenge(): void
    {
        $gateway = new class extends PhoneAuthentication
        {
            public function sendCode(string $phone, string $code): bool
            {
                return false;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0755555555', 'purpose' => 'register'])->assertStatus(503);
        $this->assertDatabaseCount('phone_auth_challenges', 0);
    }

    public function test_beem_sms_uses_secure_endpoint_and_rejects_provider_failure(): void
    {
        config()->set('services.phone_otp.provider', 'beem');
        config()->set('services.beem_sms', ['api_key' => 'test-key', 'secret_key' => 'test-secret', 'sender' => 'TEST']);
        Http::fake(['https://apisms.beem.africa/v1/send' => Http::sequence()
            ->push(['successful' => true], 200)
            ->push(['successful' => false], 200)]);
        $service = app(PhoneAuthentication::class);
        $this->assertTrue($service->sendCode('+255712345678', '123456'));
        Http::assertSent(fn ($request) => $request->url() === 'https://apisms.beem.africa/v1/send'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-key:test-secret'))
            && $request['source_addr'] === 'TEST'
            && $request['recipients'][0]['dest_addr'] === '255712345678');
        $this->assertFalse($service->sendCode('+255712345678', '123456'));
    }

    public function test_duplicate_legacy_phone_numbers_require_review(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        DB::table('system_users')->where('id', $first->roleId)->update(['phone' => '0755123456']);
        DB::table('system_users')->where('id', $second->roleId)->update(['phone' => '+255755123456']);
        $this->postJson('/api/auth/phone/check', ['phone' => '0755123456'])
            ->assertStatus(409)->assertJsonPath('code', 'PHONE_REVIEW_REQUIRED');
        $this->artisan('phone-auth:audit')->assertExitCode(1);
    }

    public function test_legacy_phone_cannot_replace_a_different_existing_identity(): void
    {
        $user = User::factory()->create();
        DB::table('system_users')->where('id', $user->roleId)->update(['phone' => '0755123456']);
        DB::table('phone_identities')->insert([
            'user_id' => $user->id, 'phone' => '+255766123456',
            'pin_hash' => bcrypt('1234'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/auth/phone/check', ['phone' => '0755123456'])
            ->assertStatus(409)->assertJsonPath('code', 'PHONE_REVIEW_REQUIRED');
    }

    public function test_legacy_account_is_linked_without_replacing_its_role(): void
    {
        $user = User::factory()->create();
        DB::table('system_users')->where('id', $user->roleId)->update([
            'phone' => '0755123456', 'firstName' => 'Asha', 'middleName' => null, 'lastName' => 'Mushi',
        ]);
        $gateway = new class extends PhoneAuthentication
        {
            public string $code = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->code = $code;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        // An existing account without a PIN only chooses one; its name comes from the record.
        $this->postJson('/api/auth/phone/check', ['phone' => '0755123456'])->assertJsonPath('next', 'setup');
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0755123456', 'purpose' => 'reset'])->assertOk();
        $proof = $this->postJson('/api/auth/phone/verify-otp', ['phone' => '0755123456', 'otp' => $gateway->code])
            ->assertOk()->assertJsonPath('displayName', 'Asha Mushi')->json('verificationToken');
        $this->postJson('/api/auth/phone/complete', ['phone' => '0755123456', 'verificationToken' => $proof, 'name' => 'Someone Else', 'pin' => '4321', 'pin_confirmation' => '4321'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.user.role', 'systemUser')
            ->assertJsonPath('data.user.name', 'Asha Mushi');
        $this->assertDatabaseHas('phone_identities', ['user_id' => $user->id, 'display_name' => 'Asha Mushi', 'pin_pending' => false]);
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/auth/phone/check', ['phone' => '0755123456'])->assertJsonPath('next', 'pin');
    }

    public function test_phone_only_account_name_is_split_into_first_middle_and_surname(): void
    {
        $gateway = new class extends PhoneAuthentication
        {
            public string $code = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->code = $code;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0712345678', 'purpose' => 'register'])->assertOk();
        $proof = $this->postJson('/api/auth/phone/verify-otp', ['phone' => '0712345678', 'otp' => $gateway->code])->json('verificationToken');
        $this->postJson('/api/auth/phone/complete', ['phone' => '0712345678', 'verificationToken' => $proof, 'name' => 'Pastor John Luena', 'pin' => '4321', 'pin_confirmation' => '4321'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Pastor John Luena')
            ->assertJsonPath('data.user.firstname', 'Pastor')
            ->assertJsonPath('data.user.middlename', 'John')
            ->assertJsonPath('data.user.surname', 'Luena')
            ->assertJsonPath('data.profile.middleName', 'John');
    }

    public function test_older_apps_register_an_existing_account_without_a_pin(): void
    {
        $user = User::factory()->create();
        DB::table('system_users')->where('id', $user->roleId)->update(['phone' => '0755123456', 'firstName' => 'Asha', 'lastName' => 'Mushi']);
        $gateway = new class extends PhoneAuthentication
        {
            public string $code = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->code = $code;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0755123456', 'purpose' => 'register'])->assertOk();
        $proof = $this->postJson('/api/auth/phone/verify-otp', ['phone' => '0755123456', 'otp' => $gateway->code])->json('verificationToken');
        $this->postJson('/api/auth/phone/complete', ['phone' => '0755123456', 'verificationToken' => $proof, 'pin' => '4321', 'pin_confirmation' => '4321'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_expired_or_exhausted_challenges_cannot_be_verified(): void
    {
        $phone = '+255755123456';
        $service = app(PhoneAuthentication::class);
        DB::table('phone_auth_challenges')->insert([
            'phone' => $phone, 'purpose' => 'register', 'code_hash' => $service->hashCode($phone, '123456'),
            'attempts' => 0, 'expires_at' => now()->subSecond(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/auth/phone/verify-otp', ['phone' => $phone, 'otp' => '123456'])->assertUnprocessable();
        DB::table('phone_auth_challenges')->where('phone', $phone)->update(['expires_at' => now()->addMinute(), 'attempts' => 5]);
        $this->postJson('/api/auth/phone/verify-otp', ['phone' => $phone, 'otp' => '123456'])->assertUnprocessable();
    }

    public function test_pin_reset_revokes_old_sessions_and_preserves_account(): void
    {
        $user = User::factory()->create();
        $gateway = new class extends PhoneAuthentication
        {
            public string $code = '';

            public function sendCode(string $phone, string $code): bool
            {
                $this->code = $code;

                return true;
            }
        };
        $this->app->instance(PhoneAuthentication::class, $gateway);
        DB::table('phone_identities')->insert([
            'user_id' => $user->id, 'phone' => '+255755123456', 'display_name' => 'Existing',
            'pin_hash' => bcrypt($gateway->pinMaterial('1234')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldToken = $user->createToken('old')->accessToken->id;
        $this->postJson('/api/auth/phone/send-otp', ['phone' => '0755123456', 'purpose' => 'reset'])->assertOk();
        $proof = $this->postJson('/api/auth/phone/verify-otp', ['phone' => '0755123456', 'otp' => $gateway->code])->assertOk()->json('verificationToken');
        $this->postJson('/api/auth/phone/complete', ['phone' => '0755123456', 'verificationToken' => $proof, 'pin' => '9876', 'pin_confirmation' => '9876'])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken]);
        $this->postJson('/api/auth/phone/login', ['phone' => '0755123456', 'pin' => '1234'])->assertUnauthorized();
        $this->postJson('/api/auth/phone/login', ['phone' => '0755123456', 'pin' => '9876'])->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_phone_login_rejects_an_inactive_legacy_profile(): void
    {
        $user = User::factory()->create();
        DB::table('system_users')->where('id', $user->roleId)->update(['status' => 'notActive']);
        $service = app(PhoneAuthentication::class);
        DB::table('phone_identities')->insert([
            'user_id' => $user->id, 'phone' => '+255755123456',
            'pin_hash' => bcrypt($service->pinMaterial('1234')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/auth/phone/login', ['phone' => '0755123456', 'pin' => '1234'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_extension_officer_phone_uses_the_matching_officer_profile(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $region = DB::table('regions')->insertGetId(['name' => 'Region', 'shortName' => 'RG', 'countryId' => $country]);
        $district = DB::table('districts')->insertGetId(['name' => 'District', 'regionId' => $region]);
        $ward = DB::table('wards')->insertGetId(['name' => 'Ward', 'districtId' => $district]);
        $profileId = DB::table('system_users')->insertGetId(['firstName' => 'Other', 'lastName' => 'Person', 'phone' => '0711000000', 'status' => 'active']);
        DB::table('extension_officers')->insert([
            'id' => $profileId, 'firstName' => 'Officer', 'lastName' => 'One', 'email' => 'officer@example.test',
            'phone' => '0722000000', 'password' => 'unused', 'gender' => 'male', 'countryId' => $country,
            'regionId' => $region, 'districtId' => $district, 'wardId' => $ward,
        ]);
        $user = User::factory()->create(['role' => 'extensionOfficer', 'roleId' => $profileId, 'email' => 'officer@example.test']);
        $service = app(PhoneAuthentication::class);
        $this->assertContains($user->id, $service->matchingUserIds('+255722000000'));
        $this->assertNotContains($user->id, $service->matchingUserIds('+255711000000'));
        DB::table('phone_identities')->insert([
            'user_id' => $user->id, 'phone' => '+255722000000',
            'pin_hash' => bcrypt($service->pinMaterial('1234')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/auth/phone/login', ['phone' => '0722000000', 'pin' => '1234'])
            ->assertOk()->assertJsonPath('data.user.firstname', 'Officer')->assertJsonMissingPath('data.profile.password');
    }

    public function test_auto_login_token_refresh_requires_no_otp_or_pin(): void
    {
        $user = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $service = app(PhoneAuthentication::class);
        DB::table('phone_identities')->insert([
            'user_id' => $user->id, 'phone' => '+255714000000', 'display_name' => 'Amani Mkulima',
            'pin_hash' => bcrypt($service->pinMaterial('4321')), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $initialToken = $user->createToken('phone')->plainTextToken;

        // Auto-login / refresh session with Bearer token without providing OTP or PIN
        $response = $this->withHeader('Authorization', 'Bearer '.$initialToken)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('data.user.firstname', 'Amani')
            ->assertJsonPath('data.user.surname', 'Mkulima')
            ->assertJsonPath('data.user.phone1', '+255714000000');

        $newToken = $response->json('data.accessToken');
        $this->assertNotEmpty($newToken);
        $this->assertNotSame($initialToken, $newToken);

        // Old token was rotated and can no longer be used
        $this->flushHeaders();
        auth()->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$initialToken)
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();

        // Fresh token is active
        $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->postJson('/api/auth/refresh')
            ->assertOk();
    }
}
