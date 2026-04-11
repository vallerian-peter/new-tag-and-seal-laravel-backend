<?php

namespace App\Http\Controllers\PrepuceBreedingStatus;

use App\Http\Controllers\Controller;
use App\Models\PrepuceBreedingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrepuceBreedingStatusController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceBreedingStatus::orderBy('name')->get()->map(static fn (PrepuceBreedingStatus $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce breeding statuses retrieved successfully', 'data' => PrepuceBreedingStatus::orderBy('name')->get()], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:prepuce_breeding_statuses,name',
            'name_sw' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = PrepuceBreedingStatus::create($request->only(['name', 'name_sw']));
        return response()->json(['status' => true, 'message' => 'Prepuce breeding status created successfully', 'data' => $row], 201);
    }

    public function adminShow(PrepuceBreedingStatus $prepuceBreedingStatus): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce breeding status retrieved successfully', 'data' => $prepuceBreedingStatus], 200);
    }

    public function adminUpdate(Request $request, PrepuceBreedingStatus $prepuceBreedingStatus): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:prepuce_breeding_statuses,name,'.$prepuceBreedingStatus->id,
            'name_sw' => 'sometimes|nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $prepuceBreedingStatus->fill($request->only(['name', 'name_sw']))->save();
        return response()->json(['status' => true, 'message' => 'Prepuce breeding status updated successfully', 'data' => $prepuceBreedingStatus], 200);
    }

    public function adminDestroy(PrepuceBreedingStatus $prepuceBreedingStatus): JsonResponse
    {
        $prepuceBreedingStatus->delete();
        return response()->json(['status' => true, 'message' => 'Prepuce breeding status deleted successfully'], 200);
    }
}

