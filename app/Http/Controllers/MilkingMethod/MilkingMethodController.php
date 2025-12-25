<?php

namespace App\Http\Controllers\MilkingMethod;

use App\Http\Controllers\Controller;
use App\Models\MilkingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MilkingMethodController extends Controller
{
    /**
     * Fetch all milking methods for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return MilkingMethod::orderBy('name')
            ->get()
            ->map(static fn (MilkingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $methods = MilkingMethod::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Milking methods retrieved successfully',
            'data' => $methods,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:milking_methods,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $method = MilkingMethod::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Milking method created successfully',
            'data' => $method,
        ], 201);
    }

    public function adminShow(MilkingMethod $milkingMethod): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Milking method retrieved successfully',
            'data' => $milkingMethod,
        ], 200);
    }

    public function adminUpdate(Request $request, MilkingMethod $milkingMethod): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:milking_methods,name,' . $milkingMethod->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $milkingMethod->fill($request->only(['name']));
        $milkingMethod->save();

        return response()->json([
            'status' => true,
            'message' => 'Milking method updated successfully',
            'data' => $milkingMethod,
        ], 200);
    }

    public function adminDestroy(MilkingMethod $milkingMethod): JsonResponse
    {
        $milkingMethod->delete();
        return response()->json([
            'status' => true,
            'message' => 'Milking method deleted successfully',
        ], 200);
    }
}

