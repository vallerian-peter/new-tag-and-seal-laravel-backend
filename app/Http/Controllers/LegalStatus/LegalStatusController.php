<?php

namespace App\Http\Controllers\LegalStatus;

use App\Http\Controllers\Controller;
use App\Models\LegalStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LegalStatusController extends Controller
{
    /**
     * Fetch all legal statuses as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return LegalStatus::orderBy('name', 'asc')
            ->get()
            ->map(function ($status) {
                return [
                    'id' => $status->id,
                    'name' => $status->name,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $statuses = LegalStatus::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Legal statuses retrieved successfully',
            'data' => $statuses,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:legal_statuses,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $status = LegalStatus::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Legal status created successfully',
            'data' => $status,
        ], 201);
    }

    public function adminShow(LegalStatus $legalStatus): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Legal status retrieved successfully',
            'data' => $legalStatus,
        ], 200);
    }

    public function adminUpdate(Request $request, LegalStatus $legalStatus): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:legal_statuses,name,' . $legalStatus->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $legalStatus->fill($request->only(['name']));
        $legalStatus->save();

        return response()->json([
            'status' => true,
            'message' => 'Legal status updated successfully',
            'data' => $legalStatus,
        ], 200);
    }

    public function adminDestroy(LegalStatus $legalStatus): JsonResponse
    {
        $legalStatus->delete();
        return response()->json([
            'status' => true,
            'message' => 'Legal status deleted successfully',
        ], 200);
    }
}

