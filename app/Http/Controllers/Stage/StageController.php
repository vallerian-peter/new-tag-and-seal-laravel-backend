<?php

namespace App\Http\Controllers\Stage;

use App\Http\Controllers\Controller;
use App\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}

