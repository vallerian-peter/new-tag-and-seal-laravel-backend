<?php

namespace App\Http\Controllers\InseminationService;

use App\Http\Controllers\Controller;
use App\Models\InseminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InseminationServiceController extends Controller
{
    /**
     * Fetch all insemination services for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return InseminationService::orderBy('name')
            ->get()
            ->map(static fn (InseminationService $service) => [
                'id' => $service->id,
                'name' => $service->name,
            ])
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $services = InseminationService::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Insemination services retrieved successfully',
            'data' => $services,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:insemination_services,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $service = InseminationService::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Insemination service created successfully',
            'data' => $service,
        ], 201);
    }

    public function adminShow(InseminationService $inseminationService): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Insemination service retrieved successfully',
            'data' => $inseminationService,
        ], 200);
    }

    public function adminUpdate(Request $request, InseminationService $inseminationService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:insemination_services,name,' . $inseminationService->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $inseminationService->fill($request->only(['name']));
        $inseminationService->save();

        return response()->json([
            'status' => true,
            'message' => 'Insemination service updated successfully',
            'data' => $inseminationService,
        ], 200);
    }

    public function adminDestroy(InseminationService $inseminationService): JsonResponse
    {
        $inseminationService->delete();
        return response()->json([
            'status' => true,
            'message' => 'Insemination service deleted successfully',
        ], 200);
    }
}

