<?php

namespace App\Http\Controllers\Logs\LivestockMarking;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Concerns\ProcessesStandardLivestockLog;
use App\Models\LivestockMarking;
use Carbon\Carbon;

class LivestockMarkingController extends Controller
{
    use ProcessesStandardLivestockLog;

    public function fetchLivestockMarkingsWithUuid($farmUuids, $livestockUuids): array
    {
        return $this->fetchStandardLogsWithUuid(
            $farmUuids,
            $livestockUuids,
            LivestockMarking::class,
            function (LivestockMarking $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'markingType' => $log->markingType,
                    'description' => $log->description,
                    'notes' => $log->notes,
                    'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            }
        );
    }

    public function processLivestockMarkings(array $logs, string $livestockUuid): array
    {
        return $this->processStandardLivestockLogs(
            $logs,
            $livestockUuid,
            LivestockMarking::class,
            'livestock marking',
            function (array $logData) {
                $markingType = isset($logData['markingType']) ? trim((string) $logData['markingType']) : 'other';
                if ($markingType === '') {
                    $markingType = 'other';
                }
                $description = isset($logData['description']) ? trim((string) $logData['description']) : null;
                $description = $description === '' ? null : $description;
                $notes = isset($logData['notes']) ? trim((string) $logData['notes']) : null;
                $notes = $notes === '' ? null : $notes;

                return [
                    'markingType' => $markingType,
                    'description' => $description,
                    'notes' => $notes,
                ];
            }
        );
    }
}
