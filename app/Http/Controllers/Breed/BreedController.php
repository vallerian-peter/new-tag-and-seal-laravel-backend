<?php

namespace App\Http\Controllers\Breed;

use App\Http\Controllers\Controller;
use App\Models\Breed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BreedController extends Controller
{
    /**
     * Fetch all breeds as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return Breed::orderBy('name', 'asc')
            ->get()
            ->map(function ($breed) {
                return [
                    'id' => $breed->id,
                    'name' => $breed->name,
                    'group' => $breed->group,
                    'livestockTypeId' => $breed->livestockTypeId,
                ];
            })
            ->toArray();
    }

}

