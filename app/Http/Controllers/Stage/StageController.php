<?php

namespace App\Http\Controllers\Stage;

use App\Http\Controllers\Controller;
use App\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StageController extends Controller
{
    /**
     * Fetch all stages for reference data sync.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Stage::query();

        // Filter by livestock type if provided
        if ($request->has('livestockTypeId')) {
            $query->where(function ($q) use ($request) {
                $q->where('livestockTypeId', $request->livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic stages
            });
        }

        $stages = $query->orderBy('name')
            ->get()
            ->map(static fn (Stage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'livestockTypeId' => $stage->livestockTypeId,
            ]);

        return response()->json([
            'status' => true,
            'message' => 'Stages retrieved successfully',
            'data' => $stages,
        ]);
    }

    /**
     * Get stages by livestock type.
     */
    public function getByLivestockType($livestockTypeId): JsonResponse
    {
        $stages = Stage::where(function ($query) use ($livestockTypeId) {
            $query->where('livestockTypeId', $livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic stages
        })
            ->orderBy('name')
            ->get()
            ->map(static fn (Stage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'livestockTypeId' => $stage->livestockTypeId,
            ]);

        return response()->json([
            'status' => true,
            'message' => 'Stages retrieved successfully',
            'data' => $stages,
        ]);
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $stages = Stage::with('livestockType')->orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Stages retrieved successfully',
            'data' => $stages,
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

        $stage = Stage::create([
            'name' => $request->name,
            'livestockTypeId' => $request->livestockTypeId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Stage created successfully',
            'data' => $stage,
        ], 201);
    }

    public function adminShow(Stage $stage): JsonResponse
    {
        $stage->load('livestockType');
        return response()->json([
            'status' => true,
            'message' => 'Stage retrieved successfully',
            'data' => $stage,
        ], 200);
    }

    public function adminUpdate(Request $request, Stage $stage): JsonResponse
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

        $stage->fill($request->only(['name', 'livestockTypeId']));
        $stage->save();

        return response()->json([
            'status' => true,
            'message' => 'Stage updated successfully',
            'data' => $stage,
        ], 200);
    }

    public function adminDestroy(Stage $stage): JsonResponse
    {
        $stage->delete();
        return response()->json([
            'status' => true,
            'message' => 'Stage deleted successfully',
        ], 200);
    }
}

