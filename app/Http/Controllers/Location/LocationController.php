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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
                    'shortName' => $district->shortName ?? null,
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
                    'shortName' => $ward->shortName ?? null,
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
                    'shortName' => $village->shortName ?? null,
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

    // ============================================================================
    // Admin Location Management (SystemUser-only CRUD APIs)
    // These methods are wired under /api/v1/admin/locations/* in routes/api.php
    // They do NOT change the existing fetch/sync behaviour.
    // ============================================================================

    /**
     * Admin: List all countries (ordered by name).
     */
    public function adminListCountries(): JsonResponse
    {
        $countries = Country::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Countries retrieved successfully',
            'data' => $countries,
        ], 200);
    }

    /**
     * Admin: Create a new country.
     */
    public function adminStoreCountry(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:countries,name',
            'shortName' => 'nullable|string|max:50|unique:countries,shortName',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $country = Country::create([
            'name' => $request->name,
            'shortName' => $request->shortName,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Country created successfully',
            'data' => $country,
        ], 201);
    }

    /**
     * Admin: Show single country.
     */
    public function adminShowCountry(Country $country): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Country retrieved successfully',
            'data' => $country,
        ], 200);
    }

    /**
     * Admin: Update existing country.
     */
    public function adminUpdateCountry(Request $request, Country $country): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:countries,name,' . $country->id,
            'shortName' => 'sometimes|nullable|string|max:50|unique:countries,shortName,' . $country->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $country->fill($request->only(['name', 'shortName']));
        $country->save();

        return response()->json([
            'status' => true,
            'message' => 'Country updated successfully',
            'data' => $country,
        ], 200);
    }

    /**
     * Admin: Delete country.
     */
    public function adminDeleteCountry(Country $country): JsonResponse
    {
        $country->delete();

        return response()->json([
            'status' => true,
            'message' => 'Country deleted successfully',
        ], 200);
    }

    /**
     * Admin: List all regions.
     */
    public function adminListRegions(): JsonResponse
    {
        $regions = Region::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Regions retrieved successfully',
            'data' => $regions,
        ], 200);
    }

    /**
     * Admin: Create a new region.
     */
    public function adminStoreRegion(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:regions,name',
            'shortName' => 'nullable|string|max:50|unique:regions,shortName',
            'countryId' => 'required|integer|exists:countries,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $region = Region::create([
            'name' => $request->name,
            'shortName' => $request->shortName,
            'countryId' => $request->countryId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Region created successfully',
            'data' => $region,
        ], 201);
    }

    /**
     * Admin: Show single region.
     */
    public function adminShowRegion(Region $region): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Region retrieved successfully',
            'data' => $region,
        ], 200);
    }

    /**
     * Admin: Update existing region.
     */
    public function adminUpdateRegion(Request $request, Region $region): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:regions,name,' . $region->id,
            'shortName' => 'sometimes|nullable|string|max:50|unique:regions,shortName,' . $region->id,
            'countryId' => 'sometimes|required|integer|exists:countries,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $region->fill($request->only(['name', 'shortName', 'countryId']));
        $region->save();

        return response()->json([
            'status' => true,
            'message' => 'Region updated successfully',
            'data' => $region,
        ], 200);
    }

    /**
     * Admin: Delete region.
     */
    public function adminDeleteRegion(Region $region): JsonResponse
    {
        $region->delete();

        return response()->json([
            'status' => true,
            'message' => 'Region deleted successfully',
        ], 200);
    }

    /**
     * Admin: List all districts.
     */
    public function adminListDistricts(): JsonResponse
    {
        $districts = District::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Districts retrieved successfully',
            'data' => $districts,
        ], 200);
    }

    /**
     * Admin: Create a new district.
     */
    public function adminStoreDistrict(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:districts,name',
            'shortName' => 'nullable|string|max:50|unique:districts,shortName',
            'regionId' => 'required|integer|exists:regions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $district = District::create([
            'name' => $request->name,
            'shortName' => $request->shortName,
            'regionId' => $request->regionId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'District created successfully',
            'data' => $district,
        ], 201);
    }

    /**
     * Admin: Show single district.
     */
    public function adminShowDistrict(District $district): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'District retrieved successfully',
            'data' => $district,
        ], 200);
    }

    /**
     * Admin: Update existing district.
     */
    public function adminUpdateDistrict(Request $request, District $district): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:districts,name,' . $district->id,
            'shortName' => 'sometimes|nullable|string|max:50|unique:districts,shortName,' . $district->id,
            'regionId' => 'sometimes|required|integer|exists:regions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $district->fill($request->only(['name', 'shortName', 'regionId']));
        $district->save();

        return response()->json([
            'status' => true,
            'message' => 'District updated successfully',
            'data' => $district,
        ], 200);
    }

    /**
     * Admin: Delete district.
     */
    public function adminDeleteDistrict(District $district): JsonResponse
    {
        $district->delete();

        return response()->json([
            'status' => true,
            'message' => 'District deleted successfully',
        ], 200);
    }

    /**
     * Admin: List all wards.
     */
    public function adminListWards(): JsonResponse
    {
        $wards = Ward::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Wards retrieved successfully',
            'data' => $wards,
        ], 200);
    }

    /**
     * Admin: Create a new ward.
     */
    public function adminStoreWard(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:wards,name',
            'shortName' => 'nullable|string|max:50|unique:wards,shortName',
            'districtId' => 'required|integer|exists:districts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ward = Ward::create([
            'name' => $request->name,
            'shortName' => $request->shortName,
            'districtId' => $request->districtId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Ward created successfully',
            'data' => $ward,
        ], 201);
    }

    /**
     * Admin: Show single ward.
     */
    public function adminShowWard(Ward $ward): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Ward retrieved successfully',
            'data' => $ward,
        ], 200);
    }

    /**
     * Admin: Update existing ward.
     */
    public function adminUpdateWard(Request $request, Ward $ward): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:wards,name,' . $ward->id,
            'shortName' => 'sometimes|nullable|string|max:50|unique:wards,shortName,' . $ward->id,
            'districtId' => 'sometimes|required|integer|exists:districts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ward->fill($request->only(['name', 'shortName', 'districtId']));
        $ward->save();

        return response()->json([
            'status' => true,
            'message' => 'Ward updated successfully',
            'data' => $ward,
        ], 200);
    }

    /**
     * Admin: Delete ward.
     */
    public function adminDeleteWard(Ward $ward): JsonResponse
    {
        $ward->delete();

        return response()->json([
            'status' => true,
            'message' => 'Ward deleted successfully',
        ], 200);
    }

    /**
     * Admin: List all villages.
     */
    public function adminListVillages(): JsonResponse
    {
        $villages = Village::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Villages retrieved successfully',
            'data' => $villages,
        ], 200);
    }

    /**
     * Admin: Create a new village.
     */
    public function adminStoreVillage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:villages,name',
            'shortName' => 'nullable|string|max:50|unique:villages,shortName',
            'wardId' => 'required|integer|exists:wards,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $village = Village::create([
            'name' => $request->name,
            'shortName' => $request->shortName,
            'wardId' => $request->wardId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Village created successfully',
            'data' => $village,
        ], 201);
    }

    /**
     * Admin: Show single village.
     */
    public function adminShowVillage(Village $village): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Village retrieved successfully',
            'data' => $village,
        ], 200);
    }

    /**
     * Admin: Update existing village.
     */
    public function adminUpdateVillage(Request $request, Village $village): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:villages,name,' . $village->id,
            'shortName' => 'sometimes|nullable|string|max:50|unique:villages,shortName,' . $village->id,
            'wardId' => 'sometimes|required|integer|exists:wards,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $village->fill($request->only(['name', 'shortName', 'wardId']));
        $village->save();

        return response()->json([
            'status' => true,
            'message' => 'Village updated successfully',
            'data' => $village,
        ], 200);
    }

    /**
     * Admin: Delete village.
     */
    public function adminDeleteVillage(Village $village): JsonResponse
    {
        $village->delete();

        return response()->json([
            'status' => true,
            'message' => 'Village deleted successfully',
        ], 200);
    }

    /**
     * Admin: List all streets.
     */
    public function adminListStreets(): JsonResponse
    {
        $streets = Street::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Streets retrieved successfully',
            'data' => $streets,
        ], 200);
    }

    /**
     * Admin: Create a new street.
     */
    public function adminStoreStreet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:streets,name',
            'wardId' => 'required|integer|exists:wards,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $street = Street::create([
            'name' => $request->name,
            'wardId' => $request->wardId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Street created successfully',
            'data' => $street,
        ], 201);
    }

    /**
     * Admin: Show single street.
     */
    public function adminShowStreet(Street $street): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Street retrieved successfully',
            'data' => $street,
        ], 200);
    }

    /**
     * Admin: Update existing street.
     */
    public function adminUpdateStreet(Request $request, Street $street): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:streets,name,' . $street->id,
            'wardId' => 'sometimes|required|integer|exists:wards,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $street->fill($request->only(['name', 'wardId']));
        $street->save();

        return response()->json([
            'status' => true,
            'message' => 'Street updated successfully',
            'data' => $street,
        ], 200);
    }

    /**
     * Admin: Delete street.
     */
    public function adminDeleteStreet(Street $street): JsonResponse
    {
        $street->delete();

        return response()->json([
            'status' => true,
            'message' => 'Street deleted successfully',
        ], 200);
    }

    /**
     * Admin: List all divisions.
     */
    public function adminListDivisions(): JsonResponse
    {
        $divisions = Division::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Divisions retrieved successfully',
            'data' => $divisions,
        ], 200);
    }

    /**
     * Admin: Create a new division.
     */
    public function adminStoreDivision(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:divisions,name',
            'districtId' => 'required|integer|exists:districts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $division = Division::create([
            'name' => $request->name,
            'districtId' => $request->districtId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Division created successfully',
            'data' => $division,
        ], 201);
    }

    /**
     * Admin: Show single division.
     */
    public function adminShowDivision(Division $division): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Division retrieved successfully',
            'data' => $division,
        ], 200);
    }

    /**
     * Admin: Update existing division.
     */
    public function adminUpdateDivision(Request $request, Division $division): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:divisions,name,' . $division->id,
            'districtId' => 'sometimes|required|integer|exists:districts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $division->fill($request->only(['name', 'districtId']));
        $division->save();

        return response()->json([
            'status' => true,
            'message' => 'Division updated successfully',
            'data' => $division,
        ], 200);
    }

    /**
     * Admin: Delete division.
     */
    public function adminDeleteDivision(Division $division): JsonResponse
    {
        $division->delete();

        return response()->json([
            'status' => true,
            'message' => 'Division deleted successfully',
        ], 200);
    }
}

