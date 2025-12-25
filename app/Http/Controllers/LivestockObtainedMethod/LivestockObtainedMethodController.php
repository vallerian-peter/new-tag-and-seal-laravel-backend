<?php

namespace App\Http\Controllers\LivestockObtainedMethod;

use App\Http\Controllers\Controller;
use App\Models\LivestockObtainedMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LivestockObtainedMethodController extends Controller
{
    /**
     * Fetch all livestock obtained methods as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return LivestockObtainedMethod::orderBy('name', 'asc')
            ->get()
            ->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $methods = LivestockObtainedMethod::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Livestock obtained methods retrieved successfully',
            'data' => $methods,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:livestock_obtained_methods,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $method = LivestockObtainedMethod::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Livestock obtained method created successfully',
            'data' => $method,
        ], 201);
    }

    public function adminShow(LivestockObtainedMethod $livestockObtainedMethod): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Livestock obtained method retrieved successfully',
            'data' => $livestockObtainedMethod,
        ], 200);
    }

    public function adminUpdate(Request $request, LivestockObtainedMethod $livestockObtainedMethod): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:livestock_obtained_methods,name,' . $livestockObtainedMethod->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $livestockObtainedMethod->fill($request->only(['name']));
        $livestockObtainedMethod->save();

        return response()->json([
            'status' => true,
            'message' => 'Livestock obtained method updated successfully',
            'data' => $livestockObtainedMethod,
        ], 200);
    }

    public function adminDestroy(LivestockObtainedMethod $livestockObtainedMethod): JsonResponse
    {
        $livestockObtainedMethod->delete();
        return response()->json([
            'status' => true,
            'message' => 'Livestock obtained method deleted successfully',
        ], 200);
    }
}

