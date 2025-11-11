<?php

namespace App\Http\Controllers\FeedingType;

use App\Http\Controllers\Controller;
use App\Models\FeedingType;

class FeedingTypeController extends Controller
{
    /**
     * Fetch all feeding types as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return FeedingType::orderBy('name', 'asc')
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                ];
            })
            ->toArray();
    }
}

