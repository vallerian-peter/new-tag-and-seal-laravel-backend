<?php

namespace App\Http\Controllers\AdministrationRoute;

use App\Http\Controllers\Controller;
use App\Models\AdministrationRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdministrationRouteController extends Controller
{
    /**
     * Fetch all administration routes (for sync/reference data).
     */
    public function fetchAll(): array
    {
        return AdministrationRoute::orderBy('name', 'asc')
            ->get()
            ->map(function ($route) {
                return [
                    'id' => $route->id,
                    'name' => $route->name,
                ];
            })
            ->toArray();
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $routes = AdministrationRoute::orderBy('name', 'asc')->get();
        return response()->json([
            'status' => true,
            'message' => 'Administration routes retrieved successfully',
            'data' => $routes,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:administration_routes,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $route = AdministrationRoute::create(['name' => $request->name]);
        return response()->json([
            'status' => true,
            'message' => 'Administration route created successfully',
            'data' => $route,
        ], 201);
    }

    public function adminShow(AdministrationRoute $administrationRoute): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Administration route retrieved successfully',
            'data' => $administrationRoute,
        ], 200);
    }

    public function adminUpdate(Request $request, AdministrationRoute $administrationRoute): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:administration_routes,name,' . $administrationRoute->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $administrationRoute->fill($request->only(['name']));
        $administrationRoute->save();

        return response()->json([
            'status' => true,
            'message' => 'Administration route updated successfully',
            'data' => $administrationRoute,
        ], 200);
    }

    public function adminDestroy(AdministrationRoute $administrationRoute): JsonResponse
    {
        $administrationRoute->delete();
        return response()->json([
            'status' => true,
            'message' => 'Administration route deleted successfully',
        ], 200);
    }
}


