<?php

namespace App\Http\Controllers\FeedingType;

use App\Http\Controllers\Controller;
use App\Models\FeedingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedingTypeController extends Controller
{
    /**
     * Fetch all feeding types as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return FeedingType::orderBy('name', 'asc')
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
        $types = FeedingType::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Feeding types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:feeding_types,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = FeedingType::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Feeding type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(FeedingType $feedingType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Feeding type retrieved successfully',
            'data' => $feedingType,
        ], 200);
    }

    public function adminUpdate(Request $request, FeedingType $feedingType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:feeding_types,name,' . $feedingType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $feedingType->fill($request->only(['name']));
        $feedingType->save();

        return response()->json([
            'status' => true,
            'message' => 'Feeding type updated successfully',
            'data' => $feedingType,
        ], 200);
    }

    public function adminDestroy(FeedingType $feedingType): JsonResponse
    {
        $feedingType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Feeding type deleted successfully',
        ], 200);
    }
}

