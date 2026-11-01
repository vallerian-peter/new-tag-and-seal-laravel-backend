<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_users_cannot_login(): void
    {
        $user = User::factory()->create(['status' => UserStatus::NOT_ACTIVE]);

        $response = $this->postJson('/api/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('status', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
