<?php

namespace App\Http\Controllers\InseminationService;

use App\Http\Controllers\Controller;
use App\Models\InseminationService;

class InseminationServiceController extends Controller
{
    /**
     * Fetch all insemination services for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return InseminationService::orderBy('name')
            ->get()
            ->map(static fn (InseminationService $service) => [
                'id' => $service->id,
                'name' => $service->name,
            ])
            ->toArray();
    }
}

