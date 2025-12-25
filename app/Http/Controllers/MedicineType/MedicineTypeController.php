<?php

namespace App\Http\Controllers\MedicineType;

use App\Http\Controllers\Controller;
use App\Models\MedicineType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MedicineTypeController extends Controller
{
    /**
     * Fetch all medicine types for sync/reference data.
     */
    public function fetchAll(): array
    {
        return MedicineType::orderBy('name', 'asc')
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
        $types = MedicineType::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Medicine types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:medicine_types,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = MedicineType::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Medicine type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(MedicineType $medicineType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Medicine type retrieved successfully',
            'data' => $medicineType,
        ], 200);
    }

    public function adminUpdate(Request $request, MedicineType $medicineType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:medicine_types,name,' . $medicineType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $medicineType->fill($request->only(['name']));
        $medicineType->save();

        return response()->json([
            'status' => true,
            'message' => 'Medicine type updated successfully',
            'data' => $medicineType,
        ], 200);
    }

    public function adminDestroy(MedicineType $medicineType): JsonResponse
    {
        $medicineType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Medicine type deleted successfully',
        ], 200);
    }
}


