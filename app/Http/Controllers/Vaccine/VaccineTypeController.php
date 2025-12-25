<?php

namespace App\Http\Controllers\Vaccine;

use App\Http\Controllers\Controller;
use App\Models\VaccineType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class VaccineTypeController extends Controller
{
    /**
     * Fetch all vaccine types ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array
    {
        /** @var Collection<int, VaccineType> $types */
        $types = VaccineType::orderBy('name')->get();

        return $types->map(static function (VaccineType $type): array {
            return [
                'id' => $type->id,
                'name' => $type->name,
            ];
        })->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $types = VaccineType::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Vaccine types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:vaccine_types,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = VaccineType::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Vaccine type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(VaccineType $vaccineType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Vaccine type retrieved successfully',
            'data' => $vaccineType,
        ], 200);
    }

    public function adminUpdate(Request $request, VaccineType $vaccineType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:vaccine_types,name,' . $vaccineType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $vaccineType->fill($request->only(['name']));
        $vaccineType->save();

        return response()->json([
            'status' => true,
            'message' => 'Vaccine type updated successfully',
            'data' => $vaccineType,
        ], 200);
    }

    public function adminDestroy(VaccineType $vaccineType): JsonResponse
    {
        $vaccineType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Vaccine type deleted successfully',
        ], 200);
    }
}

