<?php

namespace App\Enums;

class VetRequestUrgency
{
    public const LOW = 'low';
    public const NORMAL = 'normal';
    public const HIGH = 'high';
    public const EMERGENCY = 'emergency';

    public static function all(): array
    {
        return [self::LOW, self::NORMAL, self::HIGH, self::EMERGENCY];
    }

    /** Minutes before an unanswered request is widened / expired. */
    public static function responseWindowMinutes(string $urgency): int
    {
        return match ($urgency) {
            self::EMERGENCY => 10,
            self::HIGH => 20,
            self::NORMAL => 45,
            default => 120,
        };
    }
}
