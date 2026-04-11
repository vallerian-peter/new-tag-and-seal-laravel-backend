<?php

namespace App\Http\Controllers\Logs\TeethClipping;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Concerns\ProcessesStandardLivestockLog;
use App\Models\TeethClipping;
use Carbon\Carbon;

class TeethClippingController extends Controller
{
    use ProcessesStandardLivestockLog;

    public function fetchTeethClippingsWithUuid($farmUuids, $livestockUuids): array
    {
        return $this->fetchStandardLogsWithUuid(
            $farmUuids,
            $livestockUuids,
            TeethClipping::class,
            function (TeethClipping $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'method' => $log->method,
                    'notes' => $log->notes,
                    'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            }
        );
    }

    public function processTeethClippings(array $logs, string $livestockUuid): array
    {
        return $this->processStandardLivestockLogs(
            $logs,
            $livestockUuid,
            TeethClipping::class,
            'teeth clipping',
            function (array $logData) {
                $method = isset($logData['method']) ? trim((string) $logData['method']) : null;
                $method = $method === '' ? null : $method;
                $notes = isset($logData['notes']) ? trim((string) $logData['notes']) : null;
                $notes = $notes === '' ? null : $notes;

                return [
                    'method' => $method,
                    'notes' => $notes,
                ];
            }
        );
    }
}
