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
     * Transporter Organization Admin
     */
    public const TRANSPORTER = 'transporter';

    /**
     * Logistics / Tanker Driver
     */
    public const DRIVER = 'driver';

    /**
     * Dairy Factory Intake Operator
     */
    public const FACTORY_OPERATOR = 'factoryOperator';

    /**
     * Warehouse / Factory Operations Manager (Boss)
     */
    public const WAREHOUSE_MANAGER = 'warehouseManager';

    /**
     * Milk Collection Centre Operator / Clerk
     */
    public const MCC_OPERATOR = 'mccOperator';

    /**
     * Livestock & Dairy Buyer
     */
    public const BUYER = 'buyer';

    /**
     * Get all available roles
     */
    public static function all(): array
    {
        return [
            self::SYSTEM_USER,
            self::FARMER,
            self::EXTENSION_OFFICER,
            self::VET,
            self::FARM_INVITED_USER,
            self::TRANSPORTER,
            self::DRIVER,
            self::FACTORY_OPERATOR,
            self::WAREHOUSE_MANAGER,
            self::MCC_OPERATOR,
            self::BUYER,
        ];
    }

    /**
     * Get roles that manage livestock directly
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
     */
    public static function admins(): array
    {
        return [
            self::SYSTEM_USER,
        ];
    }

    /**
     * Get field worker roles
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
     */
    public static function isValid(string $role): bool
    {
        return in_array($role, self::all());
    }

    /**
     * Get role display name
     */
    public static function getDisplayName(string $role): string
    {
        return match ($role) {
            self::SYSTEM_USER => 'System User',
            self::FARMER => 'Farmer',
            self::EXTENSION_OFFICER => 'Extension Officer',
            self::VET => 'Veterinarian',
            self::FARM_INVITED_USER => 'Farm Invited User',
            self::TRANSPORTER => 'Transporter Company',
            self::DRIVER => 'Logistics Driver',
            self::FACTORY_OPERATOR => 'Factory Intake Operator',
            self::WAREHOUSE_MANAGER => 'Warehouse / Factory Manager',
            self::MCC_OPERATOR => 'MCC Collection Clerk',
            self::BUYER => 'Livestock / Dairy Buyer',
            default => 'Unknown',
        };
    }

    /**
     * Get role description
     */
    public static function getDescription(string $role): string
    {
        return match ($role) {
            self::SYSTEM_USER => 'Full system administrator with access to all features',
            self::FARMER => 'Farm owner or manager with access to farm and livestock management',
            self::EXTENSION_OFFICER => 'Agricultural extension services provider',
            self::VET => 'Veterinary professional providing animal health services',
            self::FARM_INVITED_USER => 'Guest user with limited access to specific farms',
            self::TRANSPORTER => 'Logistics organization managing milk transport tankers and drivers',
            self::DRIVER => 'Authorized driver operating milk collection tankers and executing trips',
            self::FACTORY_OPERATOR => 'Dairy processing plant officer receiving milk and operating storage tanks',
            self::WAREHOUSE_MANAGER => 'Executive manager overseeing dairy factories, warehouses, silos, and plant operators',
            self::MCC_OPERATOR => 'Milk collection centre operator performing farmer intake and chilling',
            self::BUYER => 'Commercial buyer purchasing livestock or bulk dairy products',
            default => 'No description available',
        };
    }
}
