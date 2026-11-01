<?php

namespace App\Http\Controllers\Logs\PrepuceCondition;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Concerns\ProcessesStandardLivestockLog;
use App\Models\PrepuceBreedingStatus;
use App\Models\PrepuceCondition;
use App\Models\PrepuceConditionType;
use App\Models\PrepuceSeverity;
use Carbon\Carbon;

class PrepuceConditionController extends Controller
{
    use ProcessesStandardLivestockLog;

    /**
     * @return list<int>
     */
    private static function normalizeIntList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? self::normalizeIntList($decoded) : [];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            if (is_int($item)) {
                if ($item > 0) {
                    $out[] = $item;
                }

                continue;
            }
            if (is_numeric($item)) {
                $i = (int) $item;
                if ($i > 0) {
                    $out[] = $i;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function nullableTrimString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private static function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_numeric($v)) {
            $i = (int) $v;

            return $i > 0 ? $i : null;
        }

        return null;
    }

    private static function requiredLookupId(?int $id, callable $fallback): int
    {
        if ($id !== null && $id > 0) {
            return $id;
        }
        $resolved = $fallback();

        return $resolved > 0 ? $resolved : 1;
    }

    public function fetchPrepuceConditionsWithUuid($farmUuids, $livestockUuids): array
    {
        return $this->fetchStandardLogsWithUuid(
            $farmUuids,
            $livestockUuids,
            PrepuceCondition::class,
            function (PrepuceCondition $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'conditionTypeId' => $log->conditionTypeId,
                    'severityId' => $log->severityId,
                    'clinicalSignIds' => $log->clinicalSignIds ?? [],
                    'causeRiskId' => $log->causeRiskId,
                    'treatmentGivenIds' => $log->treatmentGivenIds ?? [],
                    'medicineId' => $log->medicineId,
                    'administrationRouteId' => $log->administrationRouteId,
                    'vetId' => $log->vetId,
                    'extensionOfficerId' => $log->extensionOfficerId,
                    'quantity' => $log->quantity,
                    'dose' => $log->dose,
                    'breedingStatusId' => $log->breedingStatusId,
                    'healingStatusId' => $log->healingStatusId,
                    'followUpDate' => $log->followUpDate
                        ? Carbon::parse($log->followUpDate)->toIso8601String()
                        : null,
                    'notes' => $log->notes,
                    'eventDate' => $log->eventDate
                        ? Carbon::parse($log->eventDate)->toIso8601String()
                        : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            }
        );
    }

    public function processPrepuceConditions(array $logs, string $livestockUuid): array
    {
        return $this->processStandardLivestockLogs(
            $logs,
            $livestockUuid,
            PrepuceCondition::class,
            'prepuce condition',
            function (array $logData) {
                $clinicalSignIds = self::normalizeIntList($logData['clinicalSignIds'] ?? []);
                $treatmentGivenIds = self::normalizeIntList($logData['treatmentGivenIds'] ?? []);

                $conditionTypeId = self::requiredLookupId(
                    self::nullableInt($logData['conditionTypeId'] ?? null),
                    static fn () => (int) (PrepuceConditionType::query()->where('name', 'Other')->value('id')
                        ?? PrepuceConditionType::query()->value('id'))
                );

                $severityId = self::requiredLookupId(
                    self::nullableInt($logData['severityId'] ?? null),
                    static fn () => (int) (PrepuceSeverity::query()->where('name', 'Mild')->value('id')
                        ?? PrepuceSeverity::query()->value('id'))
                );

                $breedingStatusId = self::requiredLookupId(
                    self::nullableInt($logData['breedingStatusId'] ?? null),
                    static fn () => (int) (PrepuceBreedingStatus::query()->where('name', 'Active breeder')->value('id')
                        ?? PrepuceBreedingStatus::query()->value('id'))
                );

                return [
                    'conditionTypeId' => $conditionTypeId,
                    'severityId' => $severityId,
                    'clinicalSignIds' => $clinicalSignIds === [] ? null : $clinicalSignIds,
                    'causeRiskId' => self::nullableInt($logData['causeRiskId'] ?? null),
                    'treatmentGivenIds' => $treatmentGivenIds === [] ? null : $treatmentGivenIds,
                    'medicineId' => self::nullableInt($logData['medicineId'] ?? null),
                    'administrationRouteId' => self::nullableInt($logData['administrationRouteId'] ?? null),
                    'vetId' => self::nullableTrimString($logData['vetId'] ?? null),
                    'extensionOfficerId' => self::nullableTrimString($logData['extensionOfficerId'] ?? null),
                    'quantity' => self::nullableTrimString($logData['quantity'] ?? null),
                    'dose' => self::nullableTrimString($logData['dose'] ?? null),
                    'breedingStatusId' => $breedingStatusId,
                    'healingStatusId' => self::nullableInt($logData['healingStatusId'] ?? null),
                    'followUpDate' => isset($logData['followUpDate']) && $logData['followUpDate'] !== ''
                        ? Carbon::parse($logData['followUpDate'])->format('Y-m-d H:i:s')
                        : null,
                    'notes' => self::nullableTrimString($logData['notes'] ?? null),
                ];
            }
        );
    }
}
