<?php

namespace App\Http\Controllers\PrepuceSeverity;

use App\Http\Controllers\Controller;
use App\Models\PrepuceSeverity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrepuceSeverityController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceSeverity::orderBy('name')->get()->map(static fn (PrepuceSeverity $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce severities retrieved successfully', 'data' => PrepuceSeverity::orderBy('name')->get()], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:prepuce_severities,name',
            'name_sw' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = PrepuceSeverity::create($request->only(['name', 'name_sw']));
        return response()->json(['status' => true, 'message' => 'Prepuce severity created successfully', 'data' => $row], 201);
    }

    public function adminShow(PrepuceSeverity $prepuceSeverity): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce severity retrieved successfully', 'data' => $prepuceSeverity], 200);
    }

    public function adminUpdate(Request $request, PrepuceSeverity $prepuceSeverity): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:prepuce_severities,name,'.$prepuceSeverity->id,
            'name_sw' => 'sometimes|nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $prepuceSeverity->fill($request->only(['name', 'name_sw']))->save();
        return response()->json(['status' => true, 'message' => 'Prepuce severity updated successfully', 'data' => $prepuceSeverity], 200);
    }

    public function adminDestroy(PrepuceSeverity $prepuceSeverity): JsonResponse
    {
        $prepuceSeverity->delete();
        return response()->json(['status' => true, 'message' => 'Prepuce severity deleted successfully'], 200);
    }
}

