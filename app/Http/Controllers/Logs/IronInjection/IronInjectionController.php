<?php

namespace App\Http\Controllers\Logs\IronInjection;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Concerns\ProcessesStandardLivestockLog;
use App\Models\IronInjection;
use Carbon\Carbon;

class IronInjectionController extends Controller
{
    use ProcessesStandardLivestockLog;

    public function fetchIronInjectionsWithUuid($farmUuids, $livestockUuids): array
    {
        return $this->fetchStandardLogsWithUuid(
            $farmUuids,
            $livestockUuids,
            IronInjection::class,
            function (IronInjection $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'dosage' => $log->dosage,
                    'medicineId' => $log->medicineId,
                    'notes' => $log->notes,
                    'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            }
        );
    }

    public function processIronInjections(array $logs, string $livestockUuid): array
    {
        return $this->processStandardLivestockLogs(
            $logs,
            $livestockUuid,
            IronInjection::class,
            'iron injection',
            function (array $logData) {
                $dosage = isset($logData['dosage']) ? trim((string) $logData['dosage']) : null;
                $dosage = $dosage === '' ? null : $dosage;
                $notes = isset($logData['notes']) ? trim((string) $logData['notes']) : null;
                $notes = $notes === '' ? null : $notes;
                $medicineId = $logData['medicineId'] ?? null;
                if ($medicineId === '' || $medicineId === null) {
                    $medicineId = null;
                } else {
                    $medicineId = (int) $medicineId;
                    if ($medicineId <= 0) {
                        $medicineId = null;
                    }
                }

                return [
                    'dosage' => $dosage,
                    'medicineId' => $medicineId,
                    'notes' => $notes,
                ];
            }
        );
    }
}
