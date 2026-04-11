<?php

namespace App\Http\Controllers\TeethClippingMethod;

use App\Http\Controllers\Controller;
use App\Models\TeethClippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeethClippingMethodController extends Controller
{
    public function fetchAll(): array
    {
        return TeethClippingMethod::orderBy('name')
            ->get()
            ->map(static fn (TeethClippingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])
            ->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        $methods = TeethClippingMethod::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Teeth clipping methods retrieved successfully',
            'data' => $methods,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:teeth_clipping_methods,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $method = TeethClippingMethod::create(['name' => $request->name]);

        return response()->json([
            'status' => true,
            'message' => 'Teeth clipping method created successfully',
            'data' => $method,
        ], 201);
    }

    public function adminShow(TeethClippingMethod $teethClippingMethod): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Teeth clipping method retrieved successfully',
            'data' => $teethClippingMethod,
        ], 200);
    }

    public function adminUpdate(
        Request $request,
        TeethClippingMethod $teethClippingMethod,
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:teeth_clipping_methods,name,'.$teethClippingMethod->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $teethClippingMethod->fill($request->only(['name']));
        $teethClippingMethod->save();

        return response()->json([
            'status' => true,
            'message' => 'Teeth clipping method updated successfully',
            'data' => $teethClippingMethod,
        ], 200);
    }

    public function adminDestroy(TeethClippingMethod $teethClippingMethod): JsonResponse
    {
        $teethClippingMethod->delete();

        return response()->json([
            'status' => true,
            'message' => 'Teeth clipping method deleted successfully',
        ], 200);
    }
}
