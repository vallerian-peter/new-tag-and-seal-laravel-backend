<?php

namespace App\Http\Controllers\Medicine;

use App\Http\Controllers\Controller;
use App\Models\Medicines;

class MedicineController extends Controller
{
    /**
     * Fetch all medicines for sync/reference data.
     */
    public function fetchAll(): array
    {
        return Medicines::orderBy('name', 'asc')
            ->get()
            ->map(function ($medicine) {
                return [
                    'id' => $medicine->id,
                    'name' => $medicine->name,
                    'medicineTypeId' => $medicine->medicineTypeId,
                ];
            })
            ->toArray();
    }
}


