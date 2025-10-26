<?php

namespace App\Http\Controllers\LivestockType;

use App\Http\Controllers\Controller;
use App\Models\LivestockType;

class LivestockTypeController extends Controller
{
    /**
     * Fetch all livestock types as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return LivestockType::orderBy('name', 'asc')
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

