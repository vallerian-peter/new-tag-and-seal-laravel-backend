<?php

namespace App\Http\Controllers\PrepuceConditionType;

use App\Http\Controllers\Controller;
use App\Models\PrepuceConditionType;

class PrepuceConditionTypeController extends Controller
{
    /**
     * @return array<int, array{id:int,name:string,nameSw:?string}>
     */
    public function fetchAll(): array
    {
        return PrepuceConditionType::orderBy('name')
            ->get()
            ->map(static fn (PrepuceConditionType $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'nameSw' => $row->name_sw,
            ])->toArray();
    }
}
