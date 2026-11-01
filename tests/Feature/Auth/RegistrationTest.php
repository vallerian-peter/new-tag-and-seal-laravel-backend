<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username' => 'test.admin',
            'email' => 'test@example.com',
            'password' => 'password',
            'role' => 'systemUser',
            'firstName' => 'Test',
            'lastName' => 'Administrator',
            'phone' => '+255700000001',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.user.username', 'test.admin')
            ->assertJsonPath('data.user.role', 'systemUser')
            ->assertJsonStructure(['data' => ['accessToken']]);

        $this->assertDatabaseHas('users', [
            'username' => 'test.admin',
            'email' => 'test@example.com',
            'role' => 'systemUser',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('system_users', [
            'firstName' => 'Test',
            'lastName' => 'Administrator',
            'phone' => '+255700000001',
            'status' => 'active',
        ]);
    }
}
