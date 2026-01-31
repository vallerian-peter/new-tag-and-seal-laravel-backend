<?php

namespace App\Http\Controllers\Vet;

use App\Http\Controllers\Controller;
use App\Models\Vet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VetController extends Controller
{
    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $vets = Vet::with(['country', 'region', 'district'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Vets retrieved successfully',
            'data' => $vets,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'referenceNo' => 'nullable|string|max:255|unique:vets,referenceNo',
            'medicalLicenseNo' => 'nullable|string|max:255',
            'fullName' => 'required|string|max:255',
            'phoneNumber' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'countryId' => 'nullable|integer|exists:countries,id',
            'regionId' => 'nullable|integer|exists:regions,id',
            'districtId' => 'nullable|integer|exists:districts,id',
            'gender' => 'nullable|in:male,female',
            'dateOfBirth' => 'nullable|date',
            'identityCardTypeId' => 'nullable|integer|exists:identity_card_types,id',
            'identityNo' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,notActive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();

        // Handle name components from frontend
        if (empty($data['fullName']) && ($request->firstName || $request->lastName)) {
            $data['fullName'] = trim("{$request->firstName} {$request->middleName} {$request->lastName}");
        }

        if (empty($data['referenceNo'])) {
            $data['referenceNo'] = 'VET-' . strtoupper(\Illuminate\Support\Str::random(6));
        }

        $vet = Vet::create($data);

        $vet->load(['country', 'region', 'district']);

        return response()->json([
            'status' => true,
            'message' => 'Vet created successfully',
            'data' => $vet,
        ], 201);
    }

    public function adminShow(Vet $vet): JsonResponse
    {
        $vet->load(['country', 'region', 'district']);

        return response()->json([
            'status' => true,
            'message' => 'Vet retrieved successfully',
            'data' => $vet,
        ], 200);
    }

    public function adminUpdate(Request $request, Vet $vet): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'referenceNo' => 'sometimes|nullable|string|max:255|unique:vets,referenceNo,' . $vet->id,
            'medicalLicenseNo' => 'sometimes|nullable|string|max:255',
            'fullName' => 'sometimes|required|string|max:255',
            'phoneNumber' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'address' => 'sometimes|nullable|string',
            'countryId' => 'sometimes|nullable|integer|exists:countries,id',
            'regionId' => 'sometimes|nullable|integer|exists:regions,id',
            'districtId' => 'sometimes|nullable|integer|exists:districts,id',
            'gender' => 'sometimes|nullable|string|max:50',
            'dateOfBirth' => 'sometimes|nullable|date',
            'identityCardTypeId' => 'sometimes|nullable|integer|exists:identity_card_types,id',
            'identityNo' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $vet->fill($request->all());
        $vet->save();

        $vet->load(['country', 'region', 'district']);

        return response()->json([
            'status' => true,
            'message' => 'Vet updated successfully',
            'data' => $vet,
        ], 200);
    }

    public function adminDestroy(Vet $vet): JsonResponse
    {
        $vet->delete();

        return response()->json([
            'status' => true,
            'message' => 'Vet deleted successfully',
        ], 200);
    }
}

