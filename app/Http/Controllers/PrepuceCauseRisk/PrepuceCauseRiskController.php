<?php

namespace App\Http\Controllers\PrepuceCauseRisk;

use App\Http\Controllers\Controller;
use App\Models\PrepuceCauseRisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrepuceCauseRiskController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceCauseRisk::orderBy('name')->get()->map(static fn (PrepuceCauseRisk $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce cause risks retrieved successfully', 'data' => PrepuceCauseRisk::orderBy('name')->get()], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:prepuce_cause_risks,name',
            'name_sw' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = PrepuceCauseRisk::create($request->only(['name', 'name_sw']));
        return response()->json(['status' => true, 'message' => 'Prepuce cause risk created successfully', 'data' => $row], 201);
    }

    public function adminShow(PrepuceCauseRisk $prepuceCauseRisk): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce cause risk retrieved successfully', 'data' => $prepuceCauseRisk], 200);
    }

    public function adminUpdate(Request $request, PrepuceCauseRisk $prepuceCauseRisk): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:prepuce_cause_risks,name,'.$prepuceCauseRisk->id,
            'name_sw' => 'sometimes|nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $prepuceCauseRisk->fill($request->only(['name', 'name_sw']))->save();
        return response()->json(['status' => true, 'message' => 'Prepuce cause risk updated successfully', 'data' => $prepuceCauseRisk], 200);
    }

    public function adminDestroy(PrepuceCauseRisk $prepuceCauseRisk): JsonResponse
    {
        $prepuceCauseRisk->delete();
        return response()->json(['status' => true, 'message' => 'Prepuce cause risk deleted successfully'], 200);
    }
}

