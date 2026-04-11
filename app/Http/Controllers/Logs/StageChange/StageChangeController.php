<?php

namespace App\Http\Controllers\Logs\StageChange;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Concerns\ProcessesStandardLivestockLog;
use App\Models\Livestock;
use App\Models\StageChange;
use Carbon\Carbon;

class StageChangeController extends Controller
{
    use ProcessesStandardLivestockLog;

    public function fetchStageChangesWithUuid($farmUuids, $livestockUuids): array
    {
        return $this->fetchStandardLogsWithUuid(
            $farmUuids,
            $livestockUuids,
            StageChange::class,
            function (StageChange $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'fromStageId' => $log->fromStageId,
                    'toStageId' => $log->toStageId,
                    'notes' => $log->notes,
                    'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            }
        );
    }

    public function processStageChanges(array $logs, string $livestockUuid): array
    {
        return $this->processStandardLivestockLogs(
            $logs,
            $livestockUuid,
            StageChange::class,
            'stage change',
            function (array $logData) {
                $fromStageId = $logData['fromStageId'] ?? null;
                $toStageId = $logData['toStageId'] ?? null;
                $fromStageId = ($fromStageId === '' || $fromStageId === null) ? null : (int) $fromStageId;
                $toStageId = ($toStageId === '' || $toStageId === null) ? null : (int) $toStageId;
                if ($fromStageId !== null && $fromStageId <= 0) {
                    $fromStageId = null;
                }
                if ($toStageId !== null && $toStageId <= 0) {
                    $toStageId = null;
                }
                $notes = isset($logData['notes']) ? trim((string) $logData['notes']) : null;
                $notes = $notes === '' ? null : $notes;

                return [
                    'fromStageId' => $fromStageId,
                    'toStageId' => $toStageId,
                    'notes' => $notes,
                ];
            },
            function (string $syncAction, string $livestockUuid, array $logData): void {
                if ($syncAction === 'deleted') {
                    return;
                }
                $to = $logData['toStageId'] ?? null;
                if ($to === null || $to === '') {
                    return;
                }
                $toId = (int) $to;
                if ($toId > 0) {
                    Livestock::where('uuid', $livestockUuid)->update(['stageId' => $toId]);
                }
            }
        );
    }
}
