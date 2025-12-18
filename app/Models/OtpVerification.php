<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class OtpVerification extends Model
{
    protected $fillable = [
        'email',
        'phone',
        'otp',
        'expires_at',
        'verified',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified' => 'boolean',
    ];

    /**
     * Generate a 6-digit OTP
     */
    public static function generateOtp(): string
    {
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if OTP is expired
     */
    public function isExpired(): bool
    {
        return Carbon::now()->isAfter($this->expires_at);
    }

    /**
     * Check if OTP is valid (not expired and not verified)
     */
    public function isValid(): bool
    {
        return !$this->verified && !$this->isExpired();
    }

    /**
     * Mark OTP as verified
     */
    public function markAsVerified(): void
    {
        $this->update(['verified' => true]);
    }

    /**
     * Delete expired OTPs
     */
    public static function deleteExpired(): int
    {
        return self::where('expires_at', '<', Carbon::now())->delete();
    }
}
