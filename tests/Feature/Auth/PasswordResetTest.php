<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_request_requires_an_email_or_phone(): void
    {
        $this->postJson('/api/auth/forgot-password/send-otp')
            ->assertUnprocessable()
            ->assertJsonPath('status', false)
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_unknown_otp_cannot_reset_a_password(): void
    {
        $this->postJson('/api/auth/forgot-password/reset-password', [
            'email' => 'missing@example.com',
            'otp' => '123456',
            'newPassword' => 'new-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('status', false);
    }
}
