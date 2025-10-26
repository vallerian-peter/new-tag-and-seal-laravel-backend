<?php

namespace App\Http\Controllers\Specie;

use App\Http\Controllers\Controller;
use App\Models\Specie;

class SpecieController extends Controller
{
    /**
     * Fetch all species as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return Specie::orderBy('name', 'asc')
            ->get()
            ->map(function ($specie) {
                return [
                    'id' => $specie->id,
                    'name' => $specie->name,
                ];
            })
            ->toArray();
    }
}

