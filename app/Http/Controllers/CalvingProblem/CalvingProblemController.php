<?php

namespace App\Http\Controllers\CalvingProblem;

use App\Http\Controllers\Controller;
use App\Models\CalvingProblem;

class CalvingProblemController extends Controller
{
    /**
     * Fetch all calving problems for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return CalvingProblem::orderBy('name')
            ->get()
            ->map(static fn (CalvingProblem $problem) => [
                'id' => $problem->id,
                'name' => $problem->name,
            ])
            ->toArray();
    }
}

