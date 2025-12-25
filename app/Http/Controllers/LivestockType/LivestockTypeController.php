<?php

namespace App\Http\Controllers\LivestockType;

use App\Http\Controllers\Controller;
use App\Models\LivestockType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LivestockTypeController extends Controller
{
    /**
     * Fetch all livestock types as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return LivestockType::orderBy('name', 'asc')
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $types = LivestockType::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Livestock types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:livestock_types,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = LivestockType::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Livestock type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(LivestockType $livestockType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Livestock type retrieved successfully',
            'data' => $livestockType,
        ], 200);
    }

    public function adminUpdate(Request $request, LivestockType $livestockType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:livestock_types,name,' . $livestockType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $livestockType->fill($request->only(['name']));
        $livestockType->save();

        return response()->json([
            'status' => true,
            'message' => 'Livestock type updated successfully',
            'data' => $livestockType,
        ], 200);
    }

    public function adminDestroy(LivestockType $livestockType): JsonResponse
    {
        $livestockType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Livestock type deleted successfully',
        ], 200);
    }
}

