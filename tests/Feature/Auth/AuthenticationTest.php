<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure([
                'data' => [
                    'access_token',
                    'accessToken',
                    'memberships' => [
                        'features',
                        'cooperativeStaff',
                        'cooperativeFarmers',
                        'cooperativeStaffEntry' => [
                            'phone',
                            'display_name',
                            'cooperatives',
                            'identity_card_types',
                            'memberships',
                        ],
                        'transportation' => ['drivers', 'companies'],
                        'factories',
                        'farmAccess' => ['data', 'farmerIds'],
                    ],
                ],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('status', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('phpunit');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson('/api/auth/logout');

        $response
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
