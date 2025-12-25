<?php

namespace App\Http\Controllers\BirthProblem;

use App\Http\Controllers\Controller;
use App\Models\BirthProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BirthProblemController extends Controller
{
    /**
     * Fetch all birth problems for reference data sync.
     *
     * @param Request|null $request
     * @return array
     */
    public function fetchAll(?Request $request = null): array
    {
        $query = BirthProblem::query();

        // Filter by livestock type if provided
        if ($request && $request->has('livestockTypeId')) {
            $query->where(function ($q) use ($request) {
                $q->where('livestockTypeId', $request->livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic problems
            });
        }

        return $query->orderBy('name')
            ->get()
            ->map(static fn (BirthProblem $problem) => [
                'id' => $problem->id,
                'name' => $problem->name,
                'livestockTypeId' => $problem->livestockTypeId,
            ])
            ->toArray();
    }

    /**
     * Get birth problems by livestock type.
     *
     * @param int $livestockTypeId
     * @return array
     */
    public function getByLivestockType(int $livestockTypeId): array
    {
        return BirthProblem::where(function ($query) use ($livestockTypeId) {
            $query->where('livestockTypeId', $livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic problems
        })
            ->orderBy('name')
            ->get()
            ->map(static fn (BirthProblem $problem) => [
                'id' => $problem->id,
                'name' => $problem->name,
                'livestockTypeId' => $problem->livestockTypeId,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // These methods are wired under /api/v1/admin/reference/* in routes/api.php
    // ============================================================================

    /**
     * Admin: List all birth problems.
     */
    public function adminIndex(): JsonResponse
    {
        $problems = BirthProblem::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Birth problems retrieved successfully',
            'data' => $problems,
        ], 200);
    }

    /**
     * Admin: Create a new birth problem.
     */
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

        $problem = BirthProblem::create([
            'name' => $request->name,
            'livestockTypeId' => $request->livestockTypeId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Birth problem created successfully',
            'data' => $problem,
        ], 201);
    }

    /**
     * Admin: Show single birth problem.
     */
    public function adminShow(BirthProblem $birthProblem): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Birth problem retrieved successfully',
            'data' => $birthProblem,
        ], 200);
    }

    /**
     * Admin: Update existing birth problem.
     */
    public function adminUpdate(Request $request, BirthProblem $birthProblem): JsonResponse
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

        $birthProblem->fill($request->only(['name', 'livestockTypeId']));
        $birthProblem->save();

        return response()->json([
            'status' => true,
            'message' => 'Birth problem updated successfully',
            'data' => $birthProblem,
        ], 200);
    }

    /**
     * Admin: Delete birth problem.
     */
    public function adminDestroy(BirthProblem $birthProblem): JsonResponse
    {
        $birthProblem->delete();

        return response()->json([
            'status' => true,
            'message' => 'Birth problem deleted successfully',
        ], 200);
    }
}

