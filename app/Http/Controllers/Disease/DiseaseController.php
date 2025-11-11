<?php

namespace App\Http\Controllers\Disease;

use App\Http\Controllers\Controller;
use App\Models\Disease;

class DiseaseController extends Controller
{
    /**
     * Fetch all diseases with minimal fields for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return Disease::orderBy('name')
            ->get()
            ->map(function (Disease $disease) {
                return [
                    'id' => $disease->id,
                    'name' => $disease->name,
                    'status' => $disease->status,
                ];
            })
            ->toArray();
    }
}

