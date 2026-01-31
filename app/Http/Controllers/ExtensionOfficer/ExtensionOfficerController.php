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
            'phone' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'gender' => 'required|in:male,female',
            'licenseNumber' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'countryId' => 'required|integer|exists:countries,id',
            'regionId' => 'required|integer|exists:regions,id',
            'districtId' => 'required|integer|exists:districts,id',
            'wardId' => 'nullable|integer|exists:wards,id',
            'organization' => 'nullable|string|max:255',
            'isVerified' => 'sometimes|boolean',
            'specialization' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,notActive',
            'officerNo' => 'nullable|string|unique:extension_officers,officerNo',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        if (empty($data['officerNo'])) {
            $data['officerNo'] = 'OFF-' . strtoupper(\Illuminate\Support\Str::random(6));
        }
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
            'phone' => 'sometimes|required|string|max:255',
            'password' => 'sometimes|string|min:6',
            'gender' => 'sometimes|required|in:male,female',
            'licenseNumber' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string',
            'countryId' => 'sometimes|required|integer|exists:countries,id',
            'regionId' => 'sometimes|required|integer|exists:regions,id',
            'districtId' => 'sometimes|required|integer|exists:districts,id',
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

