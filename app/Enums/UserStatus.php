<?php

namespace App\Enums;

/**
 * User Status Enumeration
 *
 * Defines all available user statuses in the system
 */
class UserStatus
{
    /**
     * Active user - Can access the system
     */
    public const ACTIVE = 'active';

    /**
     * Inactive user - Cannot access the system
     */
    public const NOT_ACTIVE = 'notActive';

    /**
     * Get all available statuses
     *
     * @return array
     */
    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::NOT_ACTIVE,
        ];
    }

    /**
     * Check if status is valid
     *
     * @param string $status
     * @return bool
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all());
    }

    /**
     * Get status display name
     *
     * @param string $status
     * @return string
     */
    public static function getDisplayName(string $status): string
    {
        return match ($status) {
            self::ACTIVE => 'Active',
            self::NOT_ACTIVE => 'Not Active',
            default => 'Unknown',
        };
    }
}

