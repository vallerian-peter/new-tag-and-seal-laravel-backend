<?php

namespace App\Http\Controllers\PrepuceTreatmentGiven;

use App\Http\Controllers\Controller;
use App\Models\PrepuceTreatmentGiven;

class PrepuceTreatmentGivenController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceTreatmentGiven::orderBy('name')->get()->map(static fn (PrepuceTreatmentGiven $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }
}
