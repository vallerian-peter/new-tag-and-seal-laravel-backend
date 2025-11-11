<?php

namespace App\Http\Controllers\MilkingMethod;

use App\Http\Controllers\Controller;
use App\Models\MilkingMethod;

class MilkingMethodController extends Controller
{
    /**
     * Fetch all milking methods for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return MilkingMethod::orderBy('name')
            ->get()
            ->map(static fn (MilkingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])
            ->toArray();
    }
}

