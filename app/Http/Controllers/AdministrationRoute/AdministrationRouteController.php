<?php

namespace App\Http\Controllers\AdministrationRoute;

use App\Http\Controllers\Controller;
use App\Models\AdministrationRoute;

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
}


