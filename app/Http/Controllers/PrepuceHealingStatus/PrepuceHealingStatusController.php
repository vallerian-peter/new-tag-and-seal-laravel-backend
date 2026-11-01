<?php

namespace App\Http\Controllers\PrepuceHealingStatus;

use App\Http\Controllers\Controller;
use App\Models\PrepuceHealingStatus;

class PrepuceHealingStatusController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceHealingStatus::orderBy('name')->get()->map(static fn (PrepuceHealingStatus $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }
}
