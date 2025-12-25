<?php

namespace App\Http\Controllers\Breed;

use App\Http\Controllers\Controller;
use App\Models\Breed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BreedController extends Controller
{
    /**
     * Fetch all breeds as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return Breed::orderBy('name', 'asc')
            ->get()
            ->map(function ($breed) {
                return [
                    'id' => $breed->id,
                    'name' => $breed->name,
                    'group' => $breed->group,
                    'livestockTypeId' => $breed->livestockTypeId,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $breeds = Breed::with('livestockType')->orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Breeds retrieved successfully',
            'data' => $breeds,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'group' => 'nullable|string|max:255',
            'livestockTypeId' => 'nullable|integer|exists:livestock_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $breed = Breed::create([
            'name' => $request->name,
            'group' => $request->group,
            'livestockTypeId' => $request->livestockTypeId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Breed created successfully',
            'data' => $breed,
        ], 201);
    }

    public function adminShow(Breed $breed): JsonResponse
    {
        $breed->load('livestockType');
        return response()->json([
            'status' => true,
            'message' => 'Breed retrieved successfully',
            'data' => $breed,
        ], 200);
    }

    public function adminUpdate(Request $request, Breed $breed): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'group' => 'sometimes|nullable|string|max:255',
            'livestockTypeId' => 'sometimes|nullable|integer|exists:livestock_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $breed->fill($request->only(['name', 'group', 'livestockTypeId']));
        $breed->save();

        return response()->json([
            'status' => true,
            'message' => 'Breed updated successfully',
            'data' => $breed,
        ], 200);
    }

    public function adminDestroy(Breed $breed): JsonResponse
    {
        $breed->delete();
        return response()->json([
            'status' => true,
            'message' => 'Breed deleted successfully',
        ], 200);
    }
}

