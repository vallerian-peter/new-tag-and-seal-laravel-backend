<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Region;
use App\Models\District;
use App\Models\Ward;
use App\Models\Village;
use App\Models\Street;
use App\Models\Division;
use Illuminate\Http\JsonResponse;

/**
 * LocationController
 *
 * Centralized controller for fetching all location data.
 * This controller serves as the single source of truth for location operations.
 * Used by SyncController and can be used by other parts of the application.
 */
class LocationController extends Controller
{

    public function getAllLocations(): JsonResponse
    {
        try {
            $data = [
                'countries' => $this->fetchCountries(),
                'regions' => $this->fetchRegions(),
                'districts' => $this->fetchDistricts(),
                'wards' => $this->fetchWards(),
                'villages' => $this->fetchVillages(),
                'streets' => $this->fetchStreets(),
                'divisions' => $this->fetchDivisions(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'All locations retrieved successfully',
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve locations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get location metadata (counts and last updated timestamps).
     *
     * @return JsonResponse
     */
    public function getLocationMetadata(): JsonResponse
    {
        try {
            $metadata = [
                'counts' => [
                    'countries' => Country::count(),
                    'regions' => Region::count(),
                    'districts' => District::count(),
                    'wards' => Ward::count(),
                    'villages' => Village::count(),
                    'streets' => Street::count(),
                    'divisions' => Division::count(),
                ],
                'lastUpdated' => [
                    'countries' => Country::max('updated_at'),
                    'regions' => Region::max('updated_at'),
                    'districts' => District::max('updated_at'),
                    'wards' => Ward::max('updated_at'),
                    'villages' => Village::max('updated_at'),
                    'streets' => Street::max('updated_at'),
                    'divisions' => Division::max('updated_at'),
                ],
            ];

            return response()->json([
                'status' => true,
                'message' => 'Location metadata retrieved successfully',
                'data' => $metadata,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve location metadata',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ============================================================================
    // Public Fetch Methods (can be called by other controllers)
    // ============================================================================

    /**
     * Fetch all countries.
     *
     * @return array
     */
    public function fetchCountries(): array
    {
        return Country::orderBy('name', 'asc')
            ->get()
            ->map(function ($country) {
                return [
                    'id' => $country->id,
                    'name' => $country->name,
                    'shortName' => $country->shortName,
                ];
            })
            ->toArray();
    }

    /**
     * Fetch all regions.
     *
     * @return array
     */
    public function fetchRegions(): array
    {
        return Region::orderBy('name', 'asc')
            ->get()
            ->map(function ($region) {
                return [
                    'id' => $region->id,
                    'name' => $region->name,
                    'shortName' => $region->shortName,
                    'countryId' => $region->countryId,
                ];
            })
            ->toArray();
    }

    /**
     * Fetch all districts.
     *
     * @return array
     */
    public function fetchDistricts(): array
    {
        return District::orderBy('name', 'asc')
            ->get()
            ->map(function ($district) {
                return [
                    'id' => $district->id,
                    'name' => $district->name,
                    'regionId' => $district->regionId,
                ];
            })
            ->toArray();
    }

    /**
     * Fetch all wards.
     *
     * @return array
     */
    public function fetchWards(): array
    {
        return Ward::orderBy('name', 'asc')
            ->get()
            ->map(function ($ward) {
                return [
                    'id' => $ward->id,
                    'name' => $ward->name,
                    'districtId' => $ward->districtId,
                ];
            })
            ->toArray();
    }

    /**
     * Fetch all villages.
     *
     * @return array
     */
    public function fetchVillages(): array
    {
        return Village::orderBy('name', 'asc')
            ->get()
            ->map(function ($village) {
                return [
                    'id' => $village->id,
                    'name' => $village->name,
                    'wardId' => $village->wardId,
                ];
            })
            ->toArray();
    }

    /**
     * Fetch all streets.
     *
     * @return array
     */
    public function fetchStreets(): array
    {
        return Street::orderBy('name', 'asc')
            ->get()
            ->map(function ($street) {
                return [
                    'id' => $street->id,
                    'name' => $street->name,
                    'wardId' => $street->wardId,
                ];
            })
            ->toArray();
    }

    /**
     * Fetch all divisions.
     *
     * @return array
     */
    public function fetchDivisions(): array
    {
        return Division::orderBy('name', 'asc')
            ->get()
            ->map(function ($division) {
                return [
                    'id' => $division->id,
                    'name' => $division->name,
                    'districtId' => $division->districtId,
                ];
            })
            ->toArray();
    }
}

