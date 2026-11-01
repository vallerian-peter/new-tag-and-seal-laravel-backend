<?php

namespace App\Http\Controllers\PrepuceSeverity;

use App\Http\Controllers\Controller;
use App\Models\PrepuceSeverity;

class PrepuceSeverityController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceSeverity::orderBy('name')->get()->map(static fn (PrepuceSeverity $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }
}
