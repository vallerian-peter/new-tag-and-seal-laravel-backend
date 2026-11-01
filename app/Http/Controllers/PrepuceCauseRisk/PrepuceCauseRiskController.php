<?php

namespace App\Http\Controllers\PrepuceCauseRisk;

use App\Http\Controllers\Controller;
use App\Models\PrepuceCauseRisk;

class PrepuceCauseRiskController extends Controller
{
    public function fetchAll(): array
    {
        return PrepuceCauseRisk::orderBy('name')->get()->map(static fn (PrepuceCauseRisk $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'nameSw' => $row->name_sw,
        ])->toArray();
    }
}
