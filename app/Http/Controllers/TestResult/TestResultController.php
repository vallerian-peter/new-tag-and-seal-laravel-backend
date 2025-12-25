<?php

namespace App\Http\Controllers\TestResult;

use App\Http\Controllers\Controller;
use App\Models\TestResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TestResultController extends Controller
{
    /**
     * Fetch all pregnancy test results for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return TestResults::orderBy('name')
            ->get()
            ->map(static fn (TestResults $result) => [
                'id' => $result->id,
                'name' => $result->name,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $results = TestResults::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Test results retrieved successfully',
            'data' => $results,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:test_results,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = TestResults::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Test result created successfully',
            'data' => $result,
        ], 201);
    }

    public function adminShow(TestResults $testResult): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Test result retrieved successfully',
            'data' => $testResult,
        ], 200);
    }

    public function adminUpdate(Request $request, TestResults $testResult): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:test_results,name,' . $testResult->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $testResult->fill($request->only(['name']));
        $testResult->save();

        return response()->json([
            'status' => true,
            'message' => 'Test result updated successfully',
            'data' => $testResult,
        ], 200);
    }

    public function adminDestroy(TestResults $testResult): JsonResponse
    {
        $testResult->delete();
        return response()->json([
            'status' => true,
            'message' => 'Test result deleted successfully',
        ], 200);
    }
}

