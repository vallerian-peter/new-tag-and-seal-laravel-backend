<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The farmer profile fields shared by the admin portal, Farmer membership and
 * AMCOS farmer registration, so every entry point stores the same details.
 *
 * Mobile AMCOS payloads use snake_case keys; Farmer membership sends the
 * portal's camelCase keys. Both map to the same farmers columns.
 */
class FarmerProfileFields
{
    /** column => [snake key, camel key] */
    private const KEYS = [
        'firstName' => ['first_name', 'firstName'],
        'middleName' => ['middle_name', 'middleName'],
        'surname' => ['last_name', 'surname'],
        'phone2' => ['phone2', 'phone2'],
        'email' => ['email', 'email'],
        'physicalAddress' => ['physical_address', 'physicalAddress'],
        'dateOfBirth' => ['date_of_birth', 'dateOfBirth'],
        'gender' => ['gender', 'gender'],
        'farmerType' => ['farmer_type', 'farmerType'],
        'identityCardTypeId' => ['identity_card_type_id', 'identityCardTypeId'],
        'identityNumber' => ['identity_number', 'identityNumber'],
        'schoolLevelId' => ['school_level_id', 'schoolLevelId'],
        'farmerOrganizationMembership' => ['organization_name', 'farmerOrganizationMembership'],
        'countryId' => ['country_id', 'countryId'],
        'regionId' => ['region_id', 'regionId'],
        'districtId' => ['district_id', 'districtId'],
        'wardId' => ['ward_id', 'wardId'],
        'villageId' => ['village_id', 'villageId'],
        'streetId' => ['street_id', 'streetId'],
    ];

    /** Columns a farmer row cannot be without. */
    public const REQUIRED_COLUMNS = ['firstName', 'surname', 'gender', 'farmerType', 'countryId'];

    /** Validation rules matching the admin portal's farmer form. */
    public static function rules(bool $snake, string $prefix = ''): array
    {
        $rules = [
            'firstName' => ['nullable', 'string', 'max:120'],
            'middleName' => ['nullable', 'string', 'max:120'],
            'surname' => ['nullable', 'string', 'max:120'],
            'phone2' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'physicalAddress' => ['nullable', 'string', 'max:500'],
            'dateOfBirth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'farmerType' => ['nullable', Rule::in(['individual', 'organization'])],
            'identityCardTypeId' => ['nullable', 'integer', Rule::exists('identity_card_types', 'id')],
            'identityNumber' => ['nullable', 'string', 'max:64'],
            'schoolLevelId' => ['nullable', 'integer', Rule::exists('school_levels', 'id')],
            'farmerOrganizationMembership' => ['nullable', 'string', 'max:255'],
            'countryId' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'districtId' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'wardId' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'villageId' => ['nullable', 'integer', Rule::exists('villages', 'id')],
            'streetId' => ['nullable', 'integer', Rule::exists('streets', 'id')],
        ];
        $keyed = [];
        foreach ($rules as $column => $rule) {
            $keyed[$prefix.self::KEYS[$column][$snake ? 0 : 1]] = $rule;
        }

        return $keyed;
    }

    /**
     * Farmers columns for the keys present in [$input]. Blank strings become null.
     *
     * @return array<string, mixed>
     */
    public static function columns(array $input, bool $snake): array
    {
        $columns = [];
        foreach (self::KEYS as $column => $keys) {
            $key = $keys[$snake ? 0 : 1];
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = is_string($input[$key]) ? trim($input[$key]) : $input[$key];
            $columns[$column] = $value === '' ? null : $value;
        }
        if (array_key_exists('email', $columns) && $columns['email'] !== null) {
            $columns['email'] = strtolower($columns['email']);
        }

        return $columns;
    }

    /** Throws when a chosen place does not sit inside the place above it. */
    public static function assertLocationChain(array $columns, string $errorKeyPrefix = ''): void
    {
        $links = [
            'regionId' => ['regions', 'countryId', 'countryId', 'The region does not belong to the country.'],
            'districtId' => ['districts', 'regionId', 'regionId', 'The district does not belong to the region.'],
            'wardId' => ['wards', 'districtId', 'districtId', 'The ward does not belong to the district.'],
            'villageId' => ['villages', 'wardId', 'wardId', 'The village does not belong to the ward.'],
            'streetId' => ['streets', 'wardId', 'wardId', 'The street does not belong to the ward.'],
        ];
        foreach ($links as $column => [$table, $parentColumn, $parentKey, $message]) {
            $id = $columns[$column] ?? null;
            if ($id === null) {
                continue;
            }
            $parent = $columns[$parentKey] ?? null;
            if ($parent === null || ! DB::table($table)->where('id', $id)->where($parentColumn, $parent)->exists()) {
                throw ValidationException::withMessages([$errorKeyPrefix.$column => $message]);
            }
        }
    }

    /**
     * Prepares columns for the farmers table: the street is kept in the legacy
     * `street` column when there is no `streetId` column, as the portal does.
     */
    public static function forTable(array $columns): array
    {
        if (array_key_exists('streetId', $columns) && ! Schema::hasColumn('farmers', 'streetId')) {
            $columns['street'] = $columns['streetId'] !== null ? (string) $columns['streetId'] : null;
            unset($columns['streetId']);
        }

        return $columns;
    }

    /** Read columns for API responses, in the given key style. */
    public static function selects(string $alias, bool $snake): array
    {
        $streetColumn = Schema::hasColumn('farmers', 'streetId') ? 'streetId' : 'street';
        $selects = [];
        foreach (self::KEYS as $column => $keys) {
            $source = $column === 'streetId' ? $streetColumn : $column;
            $selects[] = "{$alias}.{$source} as ".$keys[$snake ? 0 : 1];
        }

        return $selects;
    }
}
