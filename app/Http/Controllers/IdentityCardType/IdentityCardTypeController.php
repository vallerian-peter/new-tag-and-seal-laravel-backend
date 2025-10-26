<?php

namespace App\Http\Controllers\IdentityCardType;

use App\Http\Controllers\Controller;
use App\Models\IdentityCardType;

class IdentityCardTypeController extends Controller
{
    /**
     * Fetch all identity card types as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return IdentityCardType::orderBy('name', 'asc')
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

