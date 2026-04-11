<?php

namespace App\Http\Controllers\PrepuceClinicalSign;

use App\Http\Controllers\Controller;
use App\Models\PrepuceClinicalSign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrepuceClinicalSignController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceClinicalSign::orderBy('name')->get()->map(static fn (PrepuceClinicalSign $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce clinical signs retrieved successfully', 'data' => PrepuceClinicalSign::orderBy('name')->get()], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:prepuce_clinical_signs,name',
            'name_sw' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $row = PrepuceClinicalSign::create($request->only(['name', 'name_sw']));
        return response()->json(['status' => true, 'message' => 'Prepuce clinical sign created successfully', 'data' => $row], 201);
    }

    public function adminShow(PrepuceClinicalSign $prepuceClinicalSign): JsonResponse
    {
        return response()->json(['status' => true, 'message' => 'Prepuce clinical sign retrieved successfully', 'data' => $prepuceClinicalSign], 200);
    }

    public function adminUpdate(Request $request, PrepuceClinicalSign $prepuceClinicalSign): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:prepuce_clinical_signs,name,'.$prepuceClinicalSign->id,
            'name_sw' => 'sometimes|nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $prepuceClinicalSign->fill($request->only(['name', 'name_sw']))->save();
        return response()->json(['status' => true, 'message' => 'Prepuce clinical sign updated successfully', 'data' => $prepuceClinicalSign], 200);
    }

    public function adminDestroy(PrepuceClinicalSign $prepuceClinicalSign): JsonResponse
    {
        $prepuceClinicalSign->delete();
        return response()->json(['status' => true, 'message' => 'Prepuce clinical sign deleted successfully'], 200);
    }
}

