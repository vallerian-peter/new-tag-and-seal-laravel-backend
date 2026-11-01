<?php

namespace App\Http\Controllers\TeethClippingMethod;

use App\Http\Controllers\Controller;
use App\Models\TeethClippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeethClippingMethodController extends Controller
{
    public function fetchAll(): array
    {
        return TeethClippingMethod::orderBy('name')
            ->get()
            ->map(static fn (TeethClippingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])
            ->toArray();
    }

}
