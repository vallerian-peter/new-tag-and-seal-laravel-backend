<?php

namespace App\Http\Controllers\Medicine;

use App\Http\Controllers\Controller;
use App\Models\Medicines;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MedicineController extends Controller
{
    /**
     * Fetch all medicines for sync/reference data.
     */
    public function fetchAll(): array
    {
        return Medicines::orderBy('name', 'asc')
            ->get()
            ->map(function ($medicine) {
                return [
                    'id' => $medicine->id,
                    'name' => $medicine->name,
                    'medicineTypeId' => $medicine->medicineTypeId,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $medicines = Medicines::with('medicineType')->orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Medicines retrieved successfully',
            'data' => $medicines,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'medicineTypeId' => 'nullable|integer|exists:medicine_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $medicine = Medicines::create([
            'name' => $request->name,
            'medicineTypeId' => $request->medicineTypeId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Medicine created successfully',
            'data' => $medicine,
        ], 201);
    }

    public function adminShow(Medicines $medicine): JsonResponse
    {
        $medicine->load('medicineType');
        return response()->json([
            'status' => true,
            'message' => 'Medicine retrieved successfully',
            'data' => $medicine,
        ], 200);
    }

    public function adminUpdate(Request $request, Medicines $medicine): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'medicineTypeId' => 'sometimes|nullable|integer|exists:medicine_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $medicine->fill($request->only(['name', 'medicineTypeId']));
        $medicine->save();

        return response()->json([
            'status' => true,
            'message' => 'Medicine updated successfully',
            'data' => $medicine,
        ], 200);
    }

    public function adminDestroy(Medicines $medicine): JsonResponse
    {
        $medicine->delete();
        return response()->json([
            'status' => true,
            'message' => 'Medicine deleted successfully',
        ], 200);
    }
}


