<?php

namespace App\Http\Controllers\TailDockingMethod;

use App\Http\Controllers\Controller;
use App\Models\TailDockingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TailDockingMethodController extends Controller
{
    public function fetchAll(): array
    {
        return TailDockingMethod::orderBy('name')
            ->get()
            ->map(static fn (TailDockingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])
            ->toArray();
    }

    public function adminIndex(): JsonResponse
    {
        $methods = TailDockingMethod::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Tail docking methods retrieved successfully',
            'data' => $methods,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:tail_docking_methods,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $method = TailDockingMethod::create(['name' => $request->name]);

        return response()->json([
            'status' => true,
            'message' => 'Tail docking method created successfully',
            'data' => $method,
        ], 201);
    }

    public function adminShow(TailDockingMethod $tailDockingMethod): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Tail docking method retrieved successfully',
            'data' => $tailDockingMethod,
        ], 200);
    }

    public function adminUpdate(Request $request, TailDockingMethod $tailDockingMethod): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:tail_docking_methods,name,'.$tailDockingMethod->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tailDockingMethod->fill($request->only(['name']));
        $tailDockingMethod->save();

        return response()->json([
            'status' => true,
            'message' => 'Tail docking method updated successfully',
            'data' => $tailDockingMethod,
        ], 200);
    }

    public function adminDestroy(TailDockingMethod $tailDockingMethod): JsonResponse
    {
        $tailDockingMethod->delete();

        return response()->json([
            'status' => true,
            'message' => 'Tail docking method deleted successfully',
        ], 200);
    }
}
