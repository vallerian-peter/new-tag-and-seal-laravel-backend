<?php

namespace App\Http\Controllers\ReproductiveProblem;

use App\Http\Controllers\Controller;
use App\Models\ReproductiveProblem;

class ReproductiveProblemController extends Controller
{
    /**
     * Fetch all reproductive problems for reference data sync.
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

