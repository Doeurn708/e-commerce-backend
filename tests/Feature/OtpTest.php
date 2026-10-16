<?php

namespace Tests\Feature;

use App\Models\Otp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_otp_successfully(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/otp/send', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'OTP sent successfully.']);

        $this->assertDatabaseHas('otps', [
            'email' => 'test@example.com',
            'verified' => false,
        ]);
    }

    public function test_it_verifies_otp_successfully(): void
    {
        Otp::create([
            'email' => 'test@example.com',
            'otp' => '123456',
            'expires_at' => now()->addMinutes(5),
            'verified' => false,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'email' => 'test@example.com',
            'otp' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'OTP verified successfully.']);

        $this->assertDatabaseHas('otps', [
            'email' => 'test@example.com',
            'verified' => true,
        ]);
    }

    public function test_it_rejects_expired_otp(): void
    {
        Otp::create([
            'email' => 'test@example.com',
            'otp' => '123456',
            'expires_at' => now()->subMinute(),
            'verified' => false,
        ]);

        $response = $this->postJson('/api/otp/verify', [
            'email' => 'test@example.com',
            'otp' => '123456',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'OTP has expired.']);
    }

    public function test_it_handles_mail_send_failure_gracefully(): void
    {
        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new \Exception('SMTP connection timed out'));

        $response = $this->postJson('/api/otp/send', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(500)
            ->assertJson(['message' => 'Failed to send OTP email. Please verify SMTP settings and try again.']);

        $this->assertDatabaseMissing('otps', [
            'email' => 'test@example.com',
        ]);
    }
}
