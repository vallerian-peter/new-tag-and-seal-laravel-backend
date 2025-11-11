<?php

namespace App\Http\Controllers\TestResult;

use App\Http\Controllers\Controller;
use App\Models\TestResults;

class TestResultController extends Controller
{
    /**
     * Fetch all pregnancy test results for reference data sync.
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return TestResults::orderBy('name')
            ->get()
            ->map(static fn (TestResults $result) => [
                'id' => $result->id,
                'name' => $result->name,
            ])
            ->toArray();
    }
}

