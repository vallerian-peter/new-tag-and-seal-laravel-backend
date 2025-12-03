<?php

namespace App\Http\Controllers\BirthType;

use App\Http\Controllers\Controller;
use App\Models\BirthType;
use Illuminate\Http\Request;

class BirthTypeController extends Controller
{
    /**
     * Fetch all birth types for reference data sync.
     *
     * @param Request|null $request
     * @return array
     */
    public function fetchAll(?Request $request = null): array
    {
        $query = BirthType::query();

        // Filter by livestock type if provided
        if ($request && $request->has('livestockTypeId')) {
            $query->where(function ($q) use ($request) {
                $q->where('livestockTypeId', $request->livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic types
            });
        }

        return $query->orderBy('name')
            ->get()
            ->map(static fn (BirthType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'livestockTypeId' => $type->livestockTypeId,
            ])
            ->toArray();
    }

    /**
     * Get birth types by livestock type.
     *
     * @param int $livestockTypeId
     * @return array
     */
    public function getByLivestockType(int $livestockTypeId): array
    {
        return BirthType::where(function ($query) use ($livestockTypeId) {
            $query->where('livestockTypeId', $livestockTypeId)
                  ->orWhereNull('livestockTypeId'); // Include generic types
        })
            ->orderBy('name')
            ->get()
            ->map(static fn (BirthType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'livestockTypeId' => $type->livestockTypeId,
            ])
            ->toArray();
    }
}

