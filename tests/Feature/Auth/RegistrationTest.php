<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_create_an_administrator(): void
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

        $response->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'username' => 'test.admin',
            'email' => 'test@example.com',
            'role' => 'systemUser',
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('system_users', [
            'firstName' => 'Test',
            'lastName' => 'Administrator',
            'phone' => '+255700000001',
            'status' => 'active',
        ]);
    }
}
