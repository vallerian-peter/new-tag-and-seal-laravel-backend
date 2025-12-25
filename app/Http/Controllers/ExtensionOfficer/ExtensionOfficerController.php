<?php

namespace App\Http\Controllers\ExtensionOfficer;

use App\Http\Controllers\Controller;
use App\Models\ExtensionOfficer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ExtensionOfficerController extends Controller
{
    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $officers = ExtensionOfficer::with(['country', 'region', 'district', 'ward'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Extension officers retrieved successfully',
            'data' => $officers,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'middleName' => 'nullable|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|unique:extension_officers,email',
            'phone' => 'nullable|string|max:255',
            'password' => 'required|string|min:6',
            'gender' => 'nullable|string|max:50',
            'licenseNumber' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'countryId' => 'nullable|integer|exists:countries,id',
            'regionId' => 'nullable|integer|exists:regions,id',
            'districtId' => 'nullable|integer|exists:districts,id',
            'wardId' => 'nullable|integer|exists:wards,id',
            'organization' => 'nullable|string|max:255',
            'isVerified' => 'sometimes|boolean',
            'specialization' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        $data['password'] = Hash::make($request->password);

        $officer = ExtensionOfficer::create($data);

        $officer->load(['country', 'region', 'district', 'ward']);

        return response()->json([
            'status' => true,
            'message' => 'Extension officer created successfully',
            'data' => $officer,
        ], 201);
    }

    public function adminShow(ExtensionOfficer $extensionOfficer): JsonResponse
    {
        $extensionOfficer->load(['country', 'region', 'district', 'ward', 'farmInvites']);

        return response()->json([
            'status' => true,
            'message' => 'Extension officer retrieved successfully',
            'data' => $extensionOfficer,
        ], 200);
    }

    public function adminUpdate(Request $request, ExtensionOfficer $extensionOfficer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'sometimes|required|string|max:255',
            'middleName' => 'sometimes|nullable|string|max:255',
            'lastName' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:extension_officers,email,' . $extensionOfficer->id,
            'phone' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|string|min:6',
            'gender' => 'sometimes|nullable|string|max:50',
            'licenseNumber' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string',
            'countryId' => 'sometimes|nullable|integer|exists:countries,id',
            'regionId' => 'sometimes|nullable|integer|exists:regions,id',
            'districtId' => 'sometimes|nullable|integer|exists:districts,id',
            'wardId' => 'sometimes|nullable|integer|exists:wards,id',
            'organization' => 'sometimes|nullable|string|max:255',
            'isVerified' => 'sometimes|boolean',
            'specialization' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['password']);
        
        if ($request->has('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $extensionOfficer->fill($data);
        $extensionOfficer->save();

        $extensionOfficer->load(['country', 'region', 'district', 'ward']);

        return response()->json([
            'status' => true,
            'message' => 'Extension officer updated successfully',
            'data' => $extensionOfficer,
        ], 200);
    }

    public function adminDestroy(ExtensionOfficer $extensionOfficer): JsonResponse
    {
        $extensionOfficer->delete();

        return response()->json([
            'status' => true,
            'message' => 'Extension officer deleted successfully',
        ], 200);
    }
}

