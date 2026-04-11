<?php

namespace App\Http\Controllers\PrepuceHealingStatus;

use App\Http\Controllers\Controller;
use App\Models\PrepuceHealingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrepuceHealingStatusController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceHealingStatus::orderBy('name')->get()->map(static fn (PrepuceHealingStatus $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce healing statuses retrieved successfully', 'data' => PrepuceHealingStatus::orderBy('name')->get()], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:prepuce_healing_statuses,name',
            'name_sw' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = PrepuceHealingStatus::create($request->only(['name', 'name_sw']));
        return response()->json(['status' => true, 'message' => 'Prepuce healing status created successfully', 'data' => $row], 201);
    }

    public function adminShow(PrepuceHealingStatus $prepuceHealingStatus): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce healing status retrieved successfully', 'data' => $prepuceHealingStatus], 200);
    }

    public function adminUpdate(Request $request, PrepuceHealingStatus $prepuceHealingStatus): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:prepuce_healing_statuses,name,'.$prepuceHealingStatus->id,
            'name_sw' => 'sometimes|nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $prepuceHealingStatus->fill($request->only(['name', 'name_sw']))->save();
        return response()->json(['status' => true, 'message' => 'Prepuce healing status updated successfully', 'data' => $prepuceHealingStatus], 200);
    }

    public function adminDestroy(PrepuceHealingStatus $prepuceHealingStatus): JsonResponse
    {
        $prepuceHealingStatus->delete();
        return response()->json(['status' => true, 'message' => 'Prepuce healing status deleted successfully'], 200);
    }
}

