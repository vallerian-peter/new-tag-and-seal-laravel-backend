<?php

namespace App\Http\Controllers\SemenStrawType;

use App\Http\Controllers\Controller;
use App\Models\SemenStrawType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SemenStrawTypeController extends Controller
{
    /**
     * Fetch all semen straw types for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return SemenStrawType::orderBy('name')
            ->get()
            ->map(static fn (SemenStrawType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'category' => $type->category,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $types = SemenStrawType::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Semen straw types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = SemenStrawType::create([
            'name' => $request->name,
            'category' => $request->category,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Semen straw type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(SemenStrawType $semenStrawType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Semen straw type retrieved successfully',
            'data' => $semenStrawType,
        ], 200);
    }

    public function adminUpdate(Request $request, SemenStrawType $semenStrawType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $semenStrawType->fill($request->only(['name', 'category']));
        $semenStrawType->save();

        return response()->json([
            'status' => true,
            'message' => 'Semen straw type updated successfully',
            'data' => $semenStrawType,
        ], 200);
    }

    public function adminDestroy(SemenStrawType $semenStrawType): JsonResponse
    {
        $semenStrawType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Semen straw type deleted successfully',
        ], 200);
    }
}

