<?php

namespace App\Http\Controllers\SchoolLevel;

use App\Http\Controllers\Controller;
use App\Models\SchoolLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SchoolLevelController extends Controller
{
    /**
     * Fetch all school levels as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return SchoolLevel::orderBy('name', 'asc')
            ->get()
            ->map(function ($level) {
                return [
                    'id' => $level->id,
                    'name' => $level->name,
                ];
            })
            ->toArray();
    }

}

