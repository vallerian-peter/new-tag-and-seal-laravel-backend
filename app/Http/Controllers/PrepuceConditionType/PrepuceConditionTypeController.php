<?php

namespace App\Http\Controllers\PrepuceConditionType;

use App\Http\Controllers\Controller;
use App\Models\PrepuceConditionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrepuceConditionTypeController extends Controller
{
    /**
     * @return array<int, array{id:int,name:string,nameSw:?string}>
     */
    public function fetchAll(): array
    {
        return PrepuceConditionType::orderBy('name')
            ->get()
            ->map(static fn (PrepuceConditionType $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'nameSw' => $row->name_sw,
            ])->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        $rows = PrepuceConditionType::orderBy('name', 'asc')->get();
        return response()->json(['status' => true, 'message' => 'Prepuce condition types retrieved successfully', 'data' => $rows], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:prepuce_condition_types,name',
            'name_sw' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $row = PrepuceConditionType::create($request->only(['name', 'name_sw']));
        return response()->json(['status' => true, 'message' => 'Prepuce condition type created successfully', 'data' => $row], 201);
    }

    public function adminShow(PrepuceConditionType $prepuceConditionType): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce condition type retrieved successfully', 'data' => $prepuceConditionType], 200);
    }

    public function adminUpdate(Request $request, PrepuceConditionType $prepuceConditionType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:prepuce_condition_types,name,'.$prepuceConditionType->id,
            'name_sw' => 'sometimes|nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $prepuceConditionType->fill($request->only(['name', 'name_sw']))->save();
        return response()->json(['status' => true, 'message' => 'Prepuce condition type updated successfully', 'data' => $prepuceConditionType], 200);
    }

    public function adminDestroy(PrepuceConditionType $prepuceConditionType): JsonResponse
    {
        $prepuceConditionType->delete();
        return response()->json(['status' => true, 'message' => 'Prepuce condition type deleted successfully'], 200);
    }
}

