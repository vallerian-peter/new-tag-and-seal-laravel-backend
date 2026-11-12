<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Farmer numbers belong to the farmer, not to an AMCOS or MCU,
 * so a farmer keeps the same number when not linked to any cooperative.
 *
 * Format: FMR-<4 random digits>-<6 random digits>, e.g. FMR-7315-483920.
 * Both parts are random so numbers reveal neither the registration year nor
 * how many farmers exist.
 */
class FarmerNumberGenerator
{
    public const PATTERN = '/^FMR-\d{4}-\d{6}$/';

    public static function generate(): string
    {
        do {
            $candidate = sprintf('FMR-%04d-%06d', random_int(1000, 9999), random_int(100000, 999999));
        } while (DB::table('farmers')->where('farmerNo', $candidate)->exists());

        return $candidate;
    }
}
