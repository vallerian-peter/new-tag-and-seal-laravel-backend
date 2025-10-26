<?php

namespace App\Http\Controllers\LegalStatus;

use App\Http\Controllers\Controller;
use App\Models\LegalStatus;

class LegalStatusController extends Controller
{
    /**
     * Fetch all legal statuses as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return LegalStatus::orderBy('name', 'asc')
            ->get()
            ->map(function ($status) {
                return [
                    'id' => $status->id,
                    'name' => $status->name,
                ];
            })
            ->toArray();
    }
}

