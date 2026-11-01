<?php

namespace App\Http\Controllers\PrepuceClinicalSign;

use App\Http\Controllers\Controller;
use App\Models\PrepuceClinicalSign;

class PrepuceClinicalSignController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceClinicalSign::orderBy('name')->get()->map(static fn (PrepuceClinicalSign $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }
}
