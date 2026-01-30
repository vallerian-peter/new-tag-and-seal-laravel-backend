<?php

namespace App\Http\Controllers\Farmer;

use App\Http\Controllers\Controller;
use App\Models\Farmer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FarmerController extends Controller
{
    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $farmers = Farmer::with([
            'identityCardType',
            'street',
            'schoolLevel',
            'village',
            'ward',
            'district',
            'region',
            'country',
            'creator'
        ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Farmers retrieved successfully',
            'data' => $farmers,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'farmerNo' => 'required|string|max:255|unique:farmers,farmerNo',
            'firstName' => 'required|string|max:255',
            'middleName' => 'nullable|string|max:255',
            'surname' => 'required|string|max:255',
            'phone1' => 'required|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'physicalAddress' => 'nullable|string',
            'farmerOrganizationMembership' => 'nullable|string|max:255',
            'dateOfBirth' => 'nullable|date',
            'gender' => 'required|in:male,female',
            'identityCardTypeId' => 'nullable|integer|exists:identity_card_types,id',
            'identityNumber' => 'nullable|string|max:255',
            'streetId' => 'nullable|integer|exists:streets,id',
            'schoolLevelId' => 'nullable|integer|exists:school_levels,id',
            'villageId' => 'nullable|integer|exists:villages,id',
            'wardId' => 'nullable|integer|exists:wards,id',
            'districtId' => 'nullable|integer|exists:districts,id',
            'regionId' => 'nullable|integer|exists:regions,id',
            'countryId' => 'nullable|integer|exists:countries,id',
            'farmerType' => 'required|in:individual,organization',
            'createdBy' => 'nullable|integer|exists:users,id',
            'status' => 'nullable|in:active,notActive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $farmer = Farmer::create($request->all());

        $farmer->load([
            'identityCardType',
            'street',
            'schoolLevel',
            'village',
            'ward',
            'district',
            'region',
            'country',
            'creator'
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Farmer created successfully',
            'data' => $farmer,
        ], 201);
    }

    public function adminShow(Farmer $farmer): JsonResponse
    {
        $farmer->load([
            'identityCardType',
            'street',
            'schoolLevel',
            'village',
            'ward',
            'district',
            'region',
            'country',
            'creator'
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Farmer retrieved successfully',
            'data' => $farmer,
        ], 200);
    }

    public function adminUpdate(Request $request, Farmer $farmer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'farmerNo' => 'sometimes|required|string|max:255|unique:farmers,farmerNo,' . $farmer->id,
            'firstName' => 'sometimes|required|string|max:255',
            'middleName' => 'sometimes|nullable|string|max:255',
            'surname' => 'sometimes|required|string|max:255',
            'phone1' => 'sometimes|required|string|max:255',
            'phone2' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'physicalAddress' => 'sometimes|nullable|string',
            'farmerOrganizationMembership' => 'sometimes|nullable|string|max:255',
            'dateOfBirth' => 'sometimes|nullable|date',
            'gender' => 'sometimes|required|in:male,female',
            'identityCardTypeId' => 'sometimes|nullable|integer|exists:identity_card_types,id',
            'identityNumber' => 'sometimes|nullable|string|max:255',
            'streetId' => 'sometimes|nullable|integer|exists:streets,id',
            'schoolLevelId' => 'sometimes|nullable|integer|exists:school_levels,id',
            'villageId' => 'sometimes|nullable|integer|exists:villages,id',
            'wardId' => 'sometimes|nullable|integer|exists:wards,id',
            'districtId' => 'sometimes|nullable|integer|exists:districts,id',
            'regionId' => 'sometimes|nullable|integer|exists:regions,id',
            'countryId' => 'sometimes|nullable|integer|exists:countries,id',
            'farmerType' => 'sometimes|required|in:individual,organization',
            'createdBy' => 'sometimes|nullable|integer|exists:users,id',
            'status' => 'sometimes|nullable|in:active,notActive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $farmer->fill($request->all());
        $farmer->save();

        $farmer->load([
            'identityCardType',
            'street',
            'schoolLevel',
            'village',
            'ward',
            'district',
            'region',
            'country',
            'creator'
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Farmer updated successfully',
            'data' => $farmer,
        ], 200);
    }

    public function adminDestroy(Farmer $farmer): JsonResponse
    {
        $farmer->delete();

        return response()->json([
            'status' => true,
            'message' => 'Farmer deleted successfully',
        ], 200);
    }
}

