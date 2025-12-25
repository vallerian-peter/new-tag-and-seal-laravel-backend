<?php

namespace App\Http\Controllers\Disease;

use App\Http\Controllers\Controller;
use App\Models\Disease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DiseaseController extends Controller
{
    /**
     * Fetch all diseases with minimal fields for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return Disease::orderBy('name')
            ->get()
            ->map(function (Disease $disease) {
                return [
                    'id' => $disease->id,
                    'name' => $disease->name,
                    'status' => $disease->status,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // These methods are wired under /api/v1/admin/reference/* in routes/api.php
    // ============================================================================

    /**
     * Admin: List all diseases.
     */
    public function adminIndex(): JsonResponse
    {
        $diseases = Disease::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Diseases retrieved successfully',
            'data' => $diseases,
        ], 200);
    }

    /**
     * Admin: Create a new disease.
     */
    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|in:spreadable,non-spreadable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $disease = Disease::create([
            'name' => $request->name,
            'status' => $request->status,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Disease created successfully',
            'data' => $disease,
        ], 201);
    }

    /**
     * Admin: Show single disease.
     */
    public function adminShow(Disease $disease): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Disease retrieved successfully',
            'data' => $disease,
        ], 200);
    }

    /**
     * Admin: Update existing disease.
     */
    public function adminUpdate(Request $request, Disease $disease): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'status' => 'sometimes|nullable|string|in:spreadable,non-spreadable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $disease->fill($request->only(['name', 'status']));
        $disease->save();

        return response()->json([
            'status' => true,
            'message' => 'Disease updated successfully',
            'data' => $disease,
        ], 200);
    }

    /**
     * Admin: Delete disease.
     */
    public function adminDestroy(Disease $disease): JsonResponse
    {
        $disease->delete();

        return response()->json([
            'status' => true,
            'message' => 'Disease deleted successfully',
        ], 200);
    }
}

