<?php

namespace App\Http\Controllers\LivestockObtainedMethod;

use App\Http\Controllers\Controller;
use App\Models\LivestockObtainedMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LivestockObtainedMethodController extends Controller
{
    /**
     * Fetch all livestock obtained methods as array (for sync).
     *
     * @return array
     */
    public function fetchAll(): array
    {
        return LivestockObtainedMethod::orderBy('name', 'asc')
            ->get()
            ->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name,
                ];
            })
            ->toArray();
    }

}

