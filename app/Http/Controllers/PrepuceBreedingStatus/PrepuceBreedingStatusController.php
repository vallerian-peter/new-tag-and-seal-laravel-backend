<?php

namespace App\Http\Controllers\PrepuceBreedingStatus;

use App\Http\Controllers\Controller;
use App\Models\PrepuceBreedingStatus;

class PrepuceBreedingStatusController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceBreedingStatus::orderBy('name')->get()->map(static fn (PrepuceBreedingStatus $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }
}
