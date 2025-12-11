<?php

namespace App\Http\Controllers\Logs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Feeding\FeedingController;
use App\Http\Controllers\Logs\WeightChange\WeightChangeController;
use App\Http\Controllers\Logs\Deworming\DewormingController;
use App\Http\Controllers\Logs\Medication\MedicationController;
use App\Http\Controllers\Logs\Vaccination\VaccinationController;
use App\Http\Controllers\Logs\Disposal\DisposalController;
use App\Http\Controllers\Logs\Birth\BirthEventController;
use App\Http\Controllers\Logs\AbortedPregnancy\AbortedPregnancyController;
use App\Http\Controllers\Logs\Milking\MilkingController;
use App\Http\Controllers\Logs\Pregnancy\PregnancyController;
use App\Http\Controllers\Logs\Insemination\InseminationController;
use App\Http\Controllers\Logs\Dryoff\DryoffController;
use App\Http\Controllers\Logs\Transfer\TransferController;

class LogController extends Controller
{
    protected $feedingController;
    protected $weightChangeController;
    protected $dewormingController;
    protected $medicationController;
    protected $vaccinationController;
    protected $disposalController;
    protected $birthEventController;
    protected $abortedPregnancyController;
    protected $milkingController;
    protected $pregnancyController;
    protected $inseminationController;
    protected $dryoffController;
    protected $transferController;

    public function __construct(
        FeedingController $feedingController,
        WeightChangeController $weightChangeController,
        DewormingController $dewormingController,
        MedicationController $medicationController,
        VaccinationController $vaccinationController,
        DisposalController $disposalController,
        BirthEventController $birthEventController,
        AbortedPregnancyController $abortedPregnancyController,
        MilkingController $milkingController,
        PregnancyController $pregnancyController,
        InseminationController $inseminationController,
        DryoffController $dryoffController,
        TransferController $transferController
    ) {
        $this->feedingController = $feedingController;
        $this->weightChangeController = $weightChangeController;
        $this->dewormingController = $dewormingController;
        $this->medicationController = $medicationController;
        $this->vaccinationController = $vaccinationController;
        $this->disposalController = $disposalController;
        $this->birthEventController = $birthEventController;
        $this->abortedPregnancyController = $abortedPregnancyController;
        $this->milkingController = $milkingController;
        $this->pregnancyController = $pregnancyController;
        $this->inseminationController = $inseminationController;
        $this->dryoffController = $dryoffController;
        $this->transferController = $transferController;
    }

    /**
     * Fetch logs scoped to the provided farm and livestock UUIDs.
     * 
     * Note: Transfers are fetched by farms only (not livestock), since transferred livestock
     * may no longer be in the source farm. Transfers will be returned if either the source
     * farm (farmUuid) or destination farm (toFarmUuid) matches the provided farm UUIDs.
     *
     * @param array $farmUuids
     * @param array $livestockUuids
     * @return array
     */
    public function fetchLogsByFarmLivestockUuids(array $farmUuids, array $livestockUuids): array
    {
        // For most logs, we need both farms and livestock
        // But transfers only need farms (livestock may have been transferred away)
        $transfers = [];
        if (!empty($farmUuids)) {
            // Transfers are fetched by farms only - livestock filtering is not needed
            $transfers = $this->transferController->fetchTransfersWithUuid($farmUuids, $livestockUuids);
        }

        // For other log types, require both farms and livestock
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [
                'feedings' => [],
                'weightChanges' => [],
                'dewormings' => [],
                'medications' => [],
                'vaccinations' => [],
                'disposals' => [],
                'birthEvents' => [],
                'abortedPregnancies' => [],
                'milkings' => [],
                'pregnancies' => [],
                'inseminations' => [],
                'dryoffs' => [],
                'transfers' => $transfers, // Return transfers even if livestockUuids is empty
            ];
        }

        return [
            'feedings' => $this->feedingController->fetchFeedingsWithUuid($farmUuids, $livestockUuids),
            'weightChanges' => $this->weightChangeController->fetchWeightChangesWithUuid($farmUuids, $livestockUuids),
            'dewormings' => $this->dewormingController->fetchDewormingsWithUuid($farmUuids, $livestockUuids),
            'medications' => $this->medicationController->fetchMedicationsWithUuid($farmUuids, $livestockUuids),
            'vaccinations' => $this->vaccinationController->fetchVaccinationsWithUuid($farmUuids, $livestockUuids),
            'disposals' => $this->disposalController->fetchDisposalsWithUuid($farmUuids, $livestockUuids),
            'birthEvents' => $this->birthEventController->fetchBirthEventsWithUuid($farmUuids, $livestockUuids),
            'abortedPregnancies' => $this->abortedPregnancyController->fetchAbortedPregnanciesWithUuid($farmUuids, $livestockUuids),
            'milkings' => $this->milkingController->fetchMilkingsWithUuid($farmUuids, $livestockUuids),
            'pregnancies' => $this->pregnancyController->fetchPregnanciesWithUuid($farmUuids, $livestockUuids),
            'inseminations' => $this->inseminationController->fetchInseminationsWithUuid($farmUuids, $livestockUuids),
            'dryoffs' => $this->dryoffController->fetchDryoffsWithUuid($farmUuids, $livestockUuids),
            'transfers' => $transfers,
        ];
    }
}

