<?php

namespace App\Http\Controllers\Specie;

use App\Http\Controllers\Controller;
use App\Models\Specie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
                    'livestockTypeId' => $specie->livestockTypeId ?? null,
                ];
            })
            ->toArray();
    }

}

