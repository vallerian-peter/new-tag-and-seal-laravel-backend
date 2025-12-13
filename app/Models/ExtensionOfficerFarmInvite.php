<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExtensionOfficerFarmInvite extends Model
{
    protected $fillable = [
        'extensionOfficerId',
        'access_code',
        'farmerId',
    ];

    /**
     * Boot the model.
     * Note: Access code should be generated from frontend and passed in the request.
     * This boot method no longer auto-generates access codes.
     */
    protected static function boot()
    {
        parent::boot();
        // Access code is now generated from frontend
    }

    /**
     * Generate access code in format: ACODE-random_numbers_3_characters=7-random_numbers
     * Total numbers (before and after =7-) must equal 7
     * Example: ACODE-12345ABC=7-12 (5 numbers + 2 numbers = 7 total)
     * Example: ACODE-1234ABC=7-123 (4 numbers + 3 numbers = 7 total)
     */
    public static function generateAccessCode(): string
    {
        // Generate random 3 uppercase characters
        $randomChars = strtoupper(Str::random(3));

        // Determine split: first part (3-5 numbers), second part (2-4 numbers), total = 7
        // Randomly choose between different splits (3+4, 4+3, 5+2)
        $splits = [[5, 2], [4, 3], [3, 4]];
        $split = $splits[array_rand($splits)];
        $firstPartLength = $split[0];
        $secondPartLength = $split[1];

        // Generate random numbers for first part
        $maxFirst = (int)str_repeat('9', $firstPartLength);
        $randomNumbers = str_pad(rand(0, $maxFirst), $firstPartLength, '0', STR_PAD_LEFT);

        // Generate random numbers for second part
        $maxSecond = (int)str_repeat('9', $secondPartLength);
        $randomEndNumbers = str_pad(rand(0, $maxSecond), $secondPartLength, '0', STR_PAD_LEFT);

        return "ACODE-{$randomNumbers}{$randomChars}-{$randomEndNumbers}";
    }

    /**
     * Generate a unique access code that doesn't already exist in the database
     */
    public static function generateUniqueAccessCode(): string
    {
        $maxAttempts = 100;
        $attempts = 0;

        do {
            $code = self::generateAccessCode();
            $exists = self::where('access_code', $code)->exists();
            $attempts++;
        } while ($exists && $attempts < $maxAttempts);

        if ($exists) {
            // Fallback: add timestamp to ensure uniqueness
            $code = self::generateAccessCode() . '-' . time();
        }

        return $code;
    }

    public function extensionOfficer(): BelongsTo
    {
        return $this->belongsTo(ExtensionOfficer::class, 'extensionOfficerId');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmerId');
    }
}
