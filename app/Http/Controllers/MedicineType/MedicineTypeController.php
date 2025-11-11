<?php

namespace App\Http\Controllers\MedicineType;

use App\Http\Controllers\Controller;
use App\Models\MedicineType;

class MedicineTypeController extends Controller
{
    /**
     * Fetch all medicine types for sync/reference data.
     */
    public function fetchAll(): array
    {
        return MedicineType::orderBy('name', 'asc')
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                ];
            })
            ->toArray();
    }
}


