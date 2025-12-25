<?php

namespace App\Http\Controllers\IdentityCardType;

use App\Http\Controllers\Controller;
use App\Models\IdentityCardType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IdentityCardTypeController extends Controller
{
    /**
     * Fetch all identity card types as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return IdentityCardType::orderBy('name', 'asc')
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $types = IdentityCardType::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Identity card types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:identity_card_types,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = IdentityCardType::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Identity card type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(IdentityCardType $identityCardType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Identity card type retrieved successfully',
            'data' => $identityCardType,
        ], 200);
    }

    public function adminUpdate(Request $request, IdentityCardType $identityCardType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:identity_card_types,name,' . $identityCardType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identityCardType->fill($request->only(['name']));
        $identityCardType->save();

        return response()->json([
            'status' => true,
            'message' => 'Identity card type updated successfully',
            'data' => $identityCardType,
        ], 200);
    }

    public function adminDestroy(IdentityCardType $identityCardType): JsonResponse
    {
        $identityCardType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Identity card type deleted successfully',
        ], 200);
    }
}

