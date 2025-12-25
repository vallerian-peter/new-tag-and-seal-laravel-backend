<?php

namespace App\Http\Controllers\ReproductiveProblem;

use App\Http\Controllers\Controller;
use App\Models\ReproductiveProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReproductiveProblemController extends Controller
{
    /**
     * Fetch all reproductive problems for reference data sync.
     * Reproductive problems are generic and apply to all livestock types.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return ReproductiveProblem::orderBy('name')
            ->get()
            ->map(static fn (ReproductiveProblem $problem) => [
                'id' => $problem->id,
                'name' => $problem->name,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $problems = ReproductiveProblem::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Reproductive problems retrieved successfully',
            'data' => $problems,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:reproductive_problems,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $problem = ReproductiveProblem::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Reproductive problem created successfully',
            'data' => $problem,
        ], 201);
    }

    public function adminShow(ReproductiveProblem $reproductiveProblem): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Reproductive problem retrieved successfully',
            'data' => $reproductiveProblem,
        ], 200);
    }

    public function adminUpdate(Request $request, ReproductiveProblem $reproductiveProblem): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:reproductive_problems,name,' . $reproductiveProblem->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $reproductiveProblem->fill($request->only(['name']));
        $reproductiveProblem->save();

        return response()->json([
            'status' => true,
            'message' => 'Reproductive problem updated successfully',
            'data' => $reproductiveProblem,
        ], 200);
    }

    public function adminDestroy(ReproductiveProblem $reproductiveProblem): JsonResponse
    {
        $reproductiveProblem->delete();
        return response()->json([
            'status' => true,
            'message' => 'Reproductive problem deleted successfully',
        ], 200);
    }
}

