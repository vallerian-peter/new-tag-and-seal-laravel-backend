<?php

namespace App\Services\Dairy;

use Illuminate\Support\Facades\DB;

/**
 * A dispatched batch's number: BAT-<4 digits>-<5 letters or digits>,
 * for example BAT-4821-K7Q2M. Unique across all dispatches.
 */
class BatchNumber
{
    public const PATTERN = '/^BAT-\d{4}-[A-Z0-9]{5}$/';

    private const CHARACTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** [proposed] when it is well formed and free for [dispatchUuid], otherwise a new number. */
    public static function resolve(?string $proposed, string $dispatchUuid): string
    {
        $proposed = strtoupper(trim((string) $proposed));
        if (preg_match(self::PATTERN, $proposed) && ! self::taken($proposed, $dispatchUuid)) {
            return $proposed;
        }
        do {
            $number = self::generate();
        } while (self::taken($number, $dispatchUuid));

        return $number;
    }

    public static function generate(): string
    {
        $suffix = '';
        for ($i = 0; $i < 5; $i++) {
            $suffix .= self::CHARACTERS[random_int(0, strlen(self::CHARACTERS) - 1)];
        }

        return sprintf('BAT-%04d-%s', random_int(0, 9999), $suffix);
    }

    private static function taken(string $number, string $dispatchUuid): bool
    {
        return DB::table('cooperative_dispatches')->where('batch_number', $number)->where('uuid', '!=', $dispatchUuid)->exists();
    }
}
