<?php

namespace App\Http\Controllers\HeatType;

use App\Http\Controllers\Controller;
use App\Models\HeatType;

class HeatTypeController extends Controller
{
    /**
     * Fetch all heat types for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return HeatType::orderBy('name')
            ->get()
            ->map(static fn (HeatType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->toArray();
    }
}

