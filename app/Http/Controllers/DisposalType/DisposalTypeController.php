<?php

namespace App\Http\Controllers\DisposalType;

use App\Http\Controllers\Controller;
use App\Models\DisposalType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DisposalTypeController extends Controller
{
    /**
     * Fetch all disposal types for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return DisposalType::orderBy('name')
            ->get()
            ->map(function (DisposalType $type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'description' => $type->description,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // These methods are wired under /api/v1/admin/reference/* in routes/api.php
    // ============================================================================

    /**
     * Admin: List all disposal types.
     */
    public function adminIndex(): JsonResponse
    {
        $types = DisposalType::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Disposal types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    /**
     * Admin: Create a new disposal type.
     */
    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = DisposalType::create([
            'name' => $request->name,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Disposal type created successfully',
            'data' => $type,
        ], 201);
    }

    /**
     * Admin: Show single disposal type.
     */
    public function adminShow(DisposalType $disposalType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Disposal type retrieved successfully',
            'data' => $disposalType,
        ], 200);
    }

    /**
     * Admin: Update existing disposal type.
     */
    public function adminUpdate(Request $request, DisposalType $disposalType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $disposalType->fill($request->only(['name']));
        $disposalType->save();

        return response()->json([
            'status' => true,
            'message' => 'Disposal type updated successfully',
            'data' => $disposalType,
        ], 200);
    }

    /**
     * Admin: Delete disposal type.
     */
    public function adminDestroy(DisposalType $disposalType): JsonResponse
    {
        $disposalType->delete();

        return response()->json([
            'status' => true,
            'message' => 'Disposal type deleted successfully',
        ], 200);
    }
}

