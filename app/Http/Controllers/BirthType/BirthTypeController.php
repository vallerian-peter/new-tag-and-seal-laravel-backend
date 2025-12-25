<?php

namespace App\Http\Controllers\BirthType;

use App\Http\Controllers\Controller;
use App\Models\BirthType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BirthTypeController extends Controller
{
    /**
     * Fetch all birth types for reference data sync.
     *
     * @param Request|null $request
     * @return array
     */
    public function fetchAll(?Request $request = null): array
    {
        $query = BirthType::query();

        // Filter by livestock type if provided
        if ($request && $request->has('livestockTypeId')) {
            $query->where(function ($q) use ($request) {
                $q->where('livestockTypeId', $request->livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic types
            });
        }

        return $query->orderBy('name')
            ->get()
            ->map(static fn (BirthType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'livestockTypeId' => $type->livestockTypeId,
            ])
            ->toArray();
    }

    /**
     * Get birth types by livestock type.
     *
     * @param int $livestockTypeId
     * @return array
     */
    public function getByLivestockType(int $livestockTypeId): array
    {
        return BirthType::where(function ($query) use ($livestockTypeId) {
            $query->where('livestockTypeId', $livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic types
        })
            ->orderBy('name')
            ->get()
            ->map(static fn (BirthType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'livestockTypeId' => $type->livestockTypeId,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // These methods are wired under /api/v1/admin/reference/* in routes/api.php
    // ============================================================================

    /**
     * Admin: List all birth types.
     */
    public function adminIndex(): JsonResponse
    {
        $types = BirthType::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Birth types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    /**
     * Admin: Create a new birth type.
     */
    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'livestockTypeId' => 'nullable|integer|exists:livestock_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = BirthType::create([
            'name' => $request->name,
            'livestockTypeId' => $request->livestockTypeId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Birth type created successfully',
            'data' => $type,
        ], 201);
    }

    /**
     * Admin: Show single birth type.
     */
    public function adminShow(BirthType $birthType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Birth type retrieved successfully',
            'data' => $birthType,
        ], 200);
    }

    /**
     * Admin: Update existing birth type.
     */
    public function adminUpdate(Request $request, BirthType $birthType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'livestockTypeId' => 'sometimes|nullable|integer|exists:livestock_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $birthType->fill($request->only(['name', 'livestockTypeId']));
        $birthType->save();

        return response()->json([
            'status' => true,
            'message' => 'Birth type updated successfully',
            'data' => $birthType,
        ], 200);
    }

    /**
     * Admin: Delete birth type.
     */
    public function adminDestroy(BirthType $birthType): JsonResponse
    {
        $birthType->delete();

        return response()->json([
            'status' => true,
            'message' => 'Birth type deleted successfully',
        ], 200);
    }
}

