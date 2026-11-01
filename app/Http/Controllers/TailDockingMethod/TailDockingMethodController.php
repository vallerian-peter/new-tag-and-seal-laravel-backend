<?php

namespace App\Http\Controllers\TailDockingMethod;

use App\Http\Controllers\Controller;
use App\Models\TailDockingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TailDockingMethodController extends Controller
{
    public function fetchAll(): array
    {
        return TailDockingMethod::orderBy('name')
            ->get()
            ->map(static fn (TailDockingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])
            ->toArray();
    }

}
