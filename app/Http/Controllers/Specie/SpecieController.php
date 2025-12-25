<?php

namespace App\Http\Controllers\Specie;

use App\Http\Controllers\Controller;
use App\Models\Specie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SpecieController extends Controller
{
    /**
     * Fetch all species as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return Specie::orderBy('name', 'asc')
            ->get()
            ->map(function ($specie) {
                return [
                    'id' => $specie->id,
                    'name' => $specie->name,
                    'livestockTypeId' => $specie->livestockTypeId ?? null,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $species = Specie::with('livestockType')->orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Species retrieved successfully',
            'data' => $species,
        ], 200);
    }

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

        $specie = Specie::create([
            'name' => $request->name,
            'livestockTypeId' => $request->livestockTypeId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Specie created successfully',
            'data' => $specie,
        ], 201);
    }

    public function adminShow(Specie $specie): JsonResponse
    {
        $specie->load('livestockType');
        return response()->json([
            'status' => true,
            'message' => 'Specie retrieved successfully',
            'data' => $specie,
        ], 200);
    }

    public function adminUpdate(Request $request, Specie $specie): JsonResponse
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

        $specie->fill($request->only(['name', 'livestockTypeId']));
        $specie->save();

        return response()->json([
            'status' => true,
            'message' => 'Specie updated successfully',
            'data' => $specie,
        ], 200);
    }

    public function adminDestroy(Specie $specie): JsonResponse
    {
        $specie->delete();
        return response()->json([
            'status' => true,
            'message' => 'Specie deleted successfully',
        ], 200);
    }
}

