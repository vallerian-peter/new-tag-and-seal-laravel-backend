<?php

namespace App\Http\Controllers\HeatType;

use App\Http\Controllers\Controller;
use App\Models\HeatType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HeatTypeController extends Controller
{
    /**
     * Fetch all heat types for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return HeatType::orderBy('name')
            ->get()
            ->map(static fn (HeatType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $types = HeatType::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Heat types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:heat_types,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = HeatType::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Heat type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(HeatType $heatType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Heat type retrieved successfully',
            'data' => $heatType,
        ], 200);
    }

    public function adminUpdate(Request $request, HeatType $heatType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:heat_types,name,' . $heatType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $heatType->fill($request->only(['name']));
        $heatType->save();

        return response()->json([
            'status' => true,
            'message' => 'Heat type updated successfully',
            'data' => $heatType,
        ], 200);
    }

    public function adminDestroy(HeatType $heatType): JsonResponse
    {
        $heatType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Heat type deleted successfully',
        ], 200);
    }
}

