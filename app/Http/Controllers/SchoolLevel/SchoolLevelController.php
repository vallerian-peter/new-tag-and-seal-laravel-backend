<?php

namespace App\Http\Controllers\SchoolLevel;

use App\Http\Controllers\Controller;
use App\Models\SchoolLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SchoolLevelController extends Controller
{
    /**
     * Fetch all school levels as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return SchoolLevel::orderBy('name', 'asc')
            ->get()
            ->map(function ($level) {
                return [
                    'id' => $level->id,
                    'name' => $level->name,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $levels = SchoolLevel::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'School levels retrieved successfully',
            'data' => $levels,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:school_levels,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $level = SchoolLevel::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'School level created successfully',
            'data' => $level,
        ], 201);
    }

    public function adminShow(SchoolLevel $schoolLevel): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'School level retrieved successfully',
            'data' => $schoolLevel,
        ], 200);
    }

    public function adminUpdate(Request $request, SchoolLevel $schoolLevel): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:school_levels,name,' . $schoolLevel->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $schoolLevel->fill($request->only(['name']));
        $schoolLevel->save();

        return response()->json([
            'status' => true,
            'message' => 'School level updated successfully',
            'data' => $schoolLevel,
        ], 200);
    }

    public function adminDestroy(SchoolLevel $schoolLevel): JsonResponse
    {
        $schoolLevel->delete();
        return response()->json([
            'status' => true,
            'message' => 'School level deleted successfully',
        ], 200);
    }
}

