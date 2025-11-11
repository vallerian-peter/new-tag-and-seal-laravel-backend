<?php

namespace App\Http\Controllers\DisposalType;

use App\Http\Controllers\Controller;
use App\Models\DisposalType;

class DisposalTypeController extends Controller
{
    /**
     * Fetch all disposal types for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return DisposalType::orderBy('name')
            ->get()
            ->map(function (DisposalType $type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'description' => $type->description,
                ];
            })
            ->toArray();
    }
}

