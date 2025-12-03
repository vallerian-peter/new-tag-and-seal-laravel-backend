<?php

namespace App\Http\Controllers\BirthProblem;

use App\Http\Controllers\Controller;
use App\Models\BirthProblem;
use Illuminate\Http\Request;

class BirthProblemController extends Controller
{
    /**
     * Fetch all birth problems for reference data sync.
     *
     * @param Request|null $request
     * @return array
     */
    public function fetchAll(?Request $request = null): array
    {
        $query = BirthProblem::query();

        // Filter by livestock type if provided
        if ($request && $request->has('livestockTypeId')) {
            $query->where(function ($q) use ($request) {
                $q->where('livestockTypeId', $request->livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic problems
            });
        }

        return $query->orderBy('name')
            ->get()
            ->map(static fn (BirthProblem $problem) => [
                'id' => $problem->id,
                'name' => $problem->name,
                'livestockTypeId' => $problem->livestockTypeId,
            ])
            ->toArray();
    }

    /**
     * Get birth problems by livestock type.
     *
     * @param int $livestockTypeId
     * @return array
     */
    public function getByLivestockType(int $livestockTypeId): array
    {
        return BirthProblem::where(function ($query) use ($livestockTypeId) {
            $query->where('livestockTypeId', $livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic problems
        })
            ->orderBy('name')
            ->get()
            ->map(static fn (BirthProblem $problem) => [
                'id' => $problem->id,
                'name' => $problem->name,
                'livestockTypeId' => $problem->livestockTypeId,
            ])
            ->toArray();
    }
}

