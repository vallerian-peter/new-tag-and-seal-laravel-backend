<?php

namespace App\Enums;

/**
 * User Role Enumeration
 *
 * Defines all available user roles in the system
 */
class UserRole
{
    /**
     * System Administrator - Full access to all system features
     */
    public const SYSTEM_USER = 'systemUser';

    /**
     * Farmer - Farm owner or manager
     */
    public const FARMER = 'farmer';

    /**
     * Extension Officer - Agricultural extension services provider
     */
    public const EXTENSION_OFFICER = 'extensionOfficer';

    /**
     * Veterinarian - Animal health professional
     */
    public const VET = 'vet';

    /**
     * Farm Invited User - Guest user with limited farm access
     */
    public const FARM_INVITED_USER = 'farmInvitedUser';

    /**
     * Get all available roles
     *
     * @return array
     */
    public static function all(): array
    {
        return [
            self::SYSTEM_USER,
            self::FARMER,
            self::EXTENSION_OFFICER,
            self::VET,
            self::FARM_INVITED_USER,
        ];
    }

    /**
     * Get roles that manage livestock directly
     *
     * @return array
     */
    public static function livestockManagers(): array
    {
        return [
            self::FARMER,
            self::VET,
            self::EXTENSION_OFFICER,
        ];
    }

    /**
     * Get administrative roles
     *
     * @return array
     */
    public static function admins(): array
    {
        return [
            self::SYSTEM_USER,
        ];
    }

    /**
     * Get field worker roles
     *
     * @return array
     */
    public static function fieldWorkers(): array
    {
        return [
            self::EXTENSION_OFFICER,
            self::VET,
        ];
    }

    /**
     * Get roles that use SystemUser profile
     *
     * @return array
     */
    public static function systemUserProfiles(): array
    {
        return [
            self::SYSTEM_USER,
            self::EXTENSION_OFFICER,
            self::VET,
            self::FARM_INVITED_USER,
        ];
    }

    /**
     * Check if role is valid
     *
     * @param string $role
     * @return bool
     */
    public static function isValid(string $role): bool
    {
        return in_array($role, self::all());
    }

    /**
     * Get role display name
     *
     * @param string $role
     * @return string
     */
    public static function getDisplayName(string $role): string
    {
        return match ($role) {
            self::SYSTEM_USER => 'System User',
            self::FARMER => 'Farmer',
            self::EXTENSION_OFFICER => 'Extension Officer',
            self::VET => 'Veterinarian',
            self::FARM_INVITED_USER => 'Farm Invited User',
            default => 'Unknown',
        };
    }

    /**
     * Get role description
     *
     * @param string $role
     * @return string
     */
    public static function getDescription(string $role): string
    {
        return match ($role) {
            self::SYSTEM_USER => 'Full system administrator with access to all features',
            self::FARMER => 'Farm owner or manager with access to farm and livestock management',
            self::EXTENSION_OFFICER => 'Agricultural extension services provider',
            self::VET => 'Veterinary professional providing animal health services',
            self::FARM_INVITED_USER => 'Guest user with limited access to specific farms',
            default => 'No description available',
        };
    }
}

