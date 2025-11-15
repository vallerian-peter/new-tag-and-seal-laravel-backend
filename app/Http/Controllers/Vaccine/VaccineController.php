<?php

namespace App\Http\Controllers\Vaccine;

use App\Http\Controllers\Controller;
use App\Models\Vaccine;
use Illuminate\Support\Collection;

class VaccineController extends Controller
{
    /**
     * Fetch all vaccines associated with the provided farm UUIDs.
     *
     * @param array $farmUuids
     * @return array
     */
    public function fetchByFarmUuids(array $farmUuids): array
    {
        if (empty($farmUuids)) {
            return [];
        }

        /** @var Collection<int, Vaccine> $vaccines */
        $vaccines = Vaccine::whereIn('farmUuid', $farmUuids)
            ->orderBy('created_at', 'desc')
            ->get();

        return $vaccines->map(function (Vaccine $vaccine) {
            return [
                'id' => $vaccine->id,
                'uuid' => $vaccine->uuid,
                'farmUuid' => $vaccine->farmUuid,
                'name' => $vaccine->name,
                'lot' => $vaccine->lot,
                'formulationType' => $vaccine->formulationType,
                'dose' => $vaccine->dose,
                'status' => $vaccine->status,
                'vaccineTypeId' => $vaccine->vaccineTypeId,
                'vaccineSchedule' => $vaccine->vaccineSchedule,
                'createdAt' => $vaccine->created_at?->toIso8601String(),
                'updatedAt' => $vaccine->updated_at?->toIso8601String(),
            ];
        })->toArray();
    }
}

