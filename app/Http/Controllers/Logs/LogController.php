<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Feeding\FeedingController;
use App\Http\Controllers\Logs\WeightChange\WeightChangeController;
use App\Http\Controllers\Logs\Deworming\DewormingController;
use App\Http\Controllers\Logs\Medication\MedicationController;
use App\Http\Controllers\Logs\Vaccination\VaccinationController;
use App\Http\Controllers\Logs\Disposal\DisposalController;

class LogController extends Controller
{
    protected $feedingController;
    protected $weightChangeController;
    protected $dewormingController;
    protected $medicationController;
    protected $vaccinationController;
    protected $disposalController;
    
    public function __construct(
        FeedingController $feedingController,
        WeightChangeController $weightChangeController,
        DewormingController $dewormingController,
        MedicationController $medicationController,
        VaccinationController $vaccinationController,
        DisposalController $disposalController
    ) {
        $this->feedingController = $feedingController;
        $this->weightChangeController = $weightChangeController;
        $this->dewormingController = $dewormingController;
        $this->medicationController = $medicationController;
        $this->vaccinationController = $vaccinationController;
        $this->disposalController = $disposalController;
    }

    /**
     * Fetch logs scoped to the provided farm and livestock UUIDs.
     *
     * @param array $farmUuids
     * @param array $livestockUuids
     * @return array
     */
    public function fetchLogsByFarmLivestockUuids(array $farmUuids, array $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [
                'feedings' => [],
                'weightChanges' => [],
                'dewormings' => [],
                'medications' => [],
                'vaccinations' => [],
                'disposals' => [],
            ];
        }

        return [
            'feedings' => $this->feedingController->fetchFeedingsWithUuid($farmUuids, $livestockUuids),
            'weightChanges' => $this->weightChangeController->fetchWeightChangesWithUuid($farmUuids, $livestockUuids),
            'dewormings' => $this->dewormingController->fetchDewormingsWithUuid($farmUuids, $livestockUuids),
            'medications' => $this->medicationController->fetchMedicationsWithUuid($farmUuids, $livestockUuids),
            'vaccinations' => $this->vaccinationController->fetchVaccinationsWithUuid($farmUuids, $livestockUuids),
            'disposals' => $this->disposalController->fetchDisposalsWithUuid($farmUuids, $livestockUuids),
        ];
    }
}

