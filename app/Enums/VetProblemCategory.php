<?php

namespace App\Enums;

/**
 * Coarse reason for a visit. Kept as codes so the app can localise labels.
 */
class VetProblemCategory
{
    public const ILLNESS = 'illness';
    public const INJURY = 'injury';
    public const CALVING = 'calving';
    public const REPRODUCTION = 'reproduction';
    public const VACCINATION = 'vaccination';
    public const DEWORMING = 'deworming';
    public const NUTRITION = 'nutrition';
    public const SUDDEN_DEATH = 'sudden_death';
    public const CHECKUP = 'checkup';
    public const OTHER = 'other';

    public static function all(): array
    {
        return [
            self::ILLNESS, self::INJURY, self::CALVING, self::REPRODUCTION,
            self::VACCINATION, self::DEWORMING, self::NUTRITION,
            self::SUDDEN_DEATH, self::CHECKUP, self::OTHER,
        ];
    }
}
