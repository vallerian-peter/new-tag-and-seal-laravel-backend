<?php

namespace App\Http\Controllers\LivestockMarkingType;

use App\Http\Controllers\Controller;
use App\Models\LivestockMarkingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LivestockMarkingTypeController extends Controller
{
    public function fetchAll(): array
    {
        return LivestockMarkingType::orderBy('name')
            ->get()
            ->map(static fn (LivestockMarkingType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        $types = LivestockMarkingType::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Livestock marking types retrieved successfully',
            'data' => $types,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:livestock_marking_types,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = LivestockMarkingType::create(['name' => $request->name]);

        return response()->json([
            'status' => true,
            'message' => 'Livestock marking type created successfully',
            'data' => $type,
        ], 201);
    }

    public function adminShow(LivestockMarkingType $livestockMarkingType): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Livestock marking type retrieved successfully',
            'data' => $livestockMarkingType,
        ], 200);
    }

    public function adminUpdate(Request $request, LivestockMarkingType $livestockMarkingType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:livestock_marking_types,name,'.$livestockMarkingType->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $livestockMarkingType->fill($request->only(['name']));
        $livestockMarkingType->save();

        return response()->json([
            'status' => true,
            'message' => 'Livestock marking type updated successfully',
            'data' => $livestockMarkingType,
        ], 200);
    }

    public function adminDestroy(LivestockMarkingType $livestockMarkingType): JsonResponse
    {
        $livestockMarkingType->delete();

        return response()->json([
            'status' => true,
            'message' => 'Livestock marking type deleted successfully',
        ], 200);
    }
}
