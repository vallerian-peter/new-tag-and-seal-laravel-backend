<?php

namespace App\Http\Controllers\ReproductiveProblem;

use App\Http\Controllers\Controller;
use App\Models\ReproductiveProblem;

class ReproductiveProblemController extends Controller
{
    /**
     * Fetch all reproductive problems for reference data sync.
     * Reproductive problems are generic and apply to all livestock types.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return ReproductiveProblem::orderBy('name')
            ->get()
            ->map(static fn (ReproductiveProblem $problem) => [
                'id' => $problem->id,
                'name' => $problem->name,
            ])
            ->toArray();
    }
}

