<?php

namespace App\Http\Controllers\CalvingType;

use App\Http\Controllers\Controller;
use App\Models\CalvingType;

class CalvingTypeController extends Controller
{
    /**
     * Fetch all calving types for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return CalvingType::orderBy('name')
            ->get()
            ->map(static fn (CalvingType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->toArray();
    }
}

