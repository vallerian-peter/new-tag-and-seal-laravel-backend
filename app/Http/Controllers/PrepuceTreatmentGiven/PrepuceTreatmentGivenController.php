<?php

namespace App\Http\Controllers\PrepuceTreatmentGiven;

use App\Http\Controllers\Controller;
use App\Models\PrepuceTreatmentGiven;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrepuceTreatmentGivenController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceTreatmentGiven::orderBy('name')->get()->map(static fn (PrepuceTreatmentGiven $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce treatments given retrieved successfully', 'data' => PrepuceTreatmentGiven::orderBy('name')->get()], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:prepuce_treatments_given,name',
            'name_sw' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = PrepuceTreatmentGiven::create($request->only(['name', 'name_sw']));
        return response()->json(['status' => true, 'message' => 'Prepuce treatment given created successfully', 'data' => $row], 201);
    }

    public function adminShow(PrepuceTreatmentGiven $prepuceTreatmentGiven): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce treatment given retrieved successfully', 'data' => $prepuceTreatmentGiven], 200);
    }

    public function adminUpdate(Request $request, PrepuceTreatmentGiven $prepuceTreatmentGiven): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:prepuce_treatments_given,name,'.$prepuceTreatmentGiven->id,
            'name_sw' => 'sometimes|nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $prepuceTreatmentGiven->fill($request->only(['name', 'name_sw']))->save();
        return response()->json(['status' => true, 'message' => 'Prepuce treatment given updated successfully', 'data' => $prepuceTreatmentGiven], 200);
    }

    public function adminDestroy(PrepuceTreatmentGiven $prepuceTreatmentGiven): JsonResponse
    {
        $prepuceTreatmentGiven->delete();
        return response()->json(['status' => true, 'message' => 'Prepuce treatment given deleted successfully'], 200);
    }
}

