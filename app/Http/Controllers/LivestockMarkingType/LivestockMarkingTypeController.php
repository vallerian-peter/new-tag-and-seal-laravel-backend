<?php

namespace App\Http\Controllers\LivestockMarkingType;

use App\Http\Controllers\Controller;
use App\Models\LivestockMarkingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LivestockMarkingTypeController extends Controller
{
    public function fetchAll(): array
    {
        return LivestockMarkingType::orderBy('name')
            ->get()
            ->map(static fn (LivestockMarkingType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->toArray();
    }

}
