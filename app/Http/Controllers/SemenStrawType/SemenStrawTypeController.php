<?php

namespace App\Http\Controllers\SemenStrawType;

use App\Http\Controllers\Controller;
use App\Models\SemenStrawType;

class SemenStrawTypeController extends Controller
{
    /**
     * Fetch all semen straw types for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return SemenStrawType::orderBy('name')
            ->get()
            ->map(static fn (SemenStrawType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'category' => $type->category,
            ])
            ->toArray();
    }
}

