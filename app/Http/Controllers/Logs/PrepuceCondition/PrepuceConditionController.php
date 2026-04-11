<?php

namespace App\Http\Controllers\Logs\PrepuceCondition;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logs\Concerns\ProcessesStandardLivestockLog;
use App\Models\PrepuceBreedingStatus;
use App\Models\PrepuceCondition;
use App\Models\PrepuceConditionType;
use App\Models\PrepuceSeverity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $rows = PrepuceCondition::with([
            'farm',
            'livestock',
            'conditionType',
            'severity',
            'causeRisk',
            'breedingStatus',
            'healingStatus',
            'medicine',
            'administrationRoute',
            'vet',
            'extensionOfficer',
        ])->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Prepuce conditions retrieved successfully',
            'data' => $rows,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:prepuce_conditions,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'conditionTypeId' => 'required|integer|exists:prepuce_condition_types,id',
            'severityId' => 'required|integer|exists:prepuce_severities,id',
            'clinicalSignIds' => 'nullable|array',
            'clinicalSignIds.*' => 'integer|exists:prepuce_clinical_signs,id',
            'causeRiskId' => 'nullable|integer|exists:prepuce_cause_risks,id',
            'treatmentGivenIds' => 'nullable|array',
            'treatmentGivenIds.*' => 'integer|exists:prepuce_treatments_given,id',
            'medicineId' => 'nullable|integer|exists:medicines,id',
            'administrationRouteId' => 'nullable|integer|exists:administration_routes,id',
            'vetId' => 'nullable|string|max:255',
            'extensionOfficerId' => 'nullable|string|max:255',
            'quantity' => 'nullable|string|max:255',
            'dose' => 'nullable|string|max:255',
            'breedingStatusId' => 'required|integer|exists:prepuce_breeding_statuses,id',
            'healingStatusId' => 'nullable|integer|exists:prepuce_healing_statuses,id',
            'followUpDate' => 'nullable|date',
            'notes' => 'nullable|string',
            'eventDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only([
            'uuid', 'farmUuid', 'livestockUuid', 'conditionTypeId', 'severityId',
            'clinicalSignIds', 'causeRiskId', 'treatmentGivenIds', 'medicineId',
            'administrationRouteId', 'vetId', 'extensionOfficerId', 'quantity', 'dose',
            'breedingStatusId', 'healingStatusId', 'notes',
        ]);
        $data['eventDate'] = $request->filled('eventDate')
            ? Carbon::parse((string) $request->eventDate)->format('Y-m-d H:i:s')
            : now()->format('Y-m-d H:i:s');
        $data['followUpDate'] = $request->filled('followUpDate')
            ? Carbon::parse((string) $request->followUpDate)->format('Y-m-d H:i:s')
            : null;

        $row = PrepuceCondition::create($data);
        $row->load(['farm', 'livestock', 'conditionType', 'severity', 'causeRisk', 'breedingStatus', 'healingStatus']);

        return response()->json([
            'status' => true,
            'message' => 'Prepuce condition created successfully',
            'data' => $row,
        ], 201);
    }

    public function adminShow(PrepuceCondition $prepuceCondition): JsonResponse
    {
        $prepuceCondition->load(['farm', 'livestock', 'conditionType', 'severity', 'causeRisk', 'breedingStatus', 'healingStatus']);
        return response()->json([
            'status' => true,
            'message' => 'Prepuce condition retrieved successfully',
            'data' => $prepuceCondition,
        ], 200);
    }

    public function adminUpdate(Request $request, PrepuceCondition $prepuceCondition): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:prepuce_conditions,uuid,'.$prepuceCondition->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'conditionTypeId' => 'sometimes|required|integer|exists:prepuce_condition_types,id',
            'severityId' => 'sometimes|required|integer|exists:prepuce_severities,id',
            'clinicalSignIds' => 'sometimes|nullable|array',
            'clinicalSignIds.*' => 'integer|exists:prepuce_clinical_signs,id',
            'causeRiskId' => 'sometimes|nullable|integer|exists:prepuce_cause_risks,id',
            'treatmentGivenIds' => 'sometimes|nullable|array',
            'treatmentGivenIds.*' => 'integer|exists:prepuce_treatments_given,id',
            'medicineId' => 'sometimes|nullable|integer|exists:medicines,id',
            'administrationRouteId' => 'sometimes|nullable|integer|exists:administration_routes,id',
            'vetId' => 'sometimes|nullable|string|max:255',
            'extensionOfficerId' => 'sometimes|nullable|string|max:255',
            'quantity' => 'sometimes|nullable|string|max:255',
            'dose' => 'sometimes|nullable|string|max:255',
            'breedingStatusId' => 'sometimes|required|integer|exists:prepuce_breeding_statuses,id',
            'healingStatusId' => 'sometimes|nullable|integer|exists:prepuce_healing_statuses,id',
            'followUpDate' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
            'eventDate' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['eventDate', 'followUpDate']);
        if ($request->has('eventDate')) {
            $data['eventDate'] = $request->filled('eventDate')
                ? Carbon::parse((string) $request->eventDate)->format('Y-m-d H:i:s')
                : null;
        }
        if ($request->has('followUpDate')) {
            $data['followUpDate'] = $request->filled('followUpDate')
                ? Carbon::parse((string) $request->followUpDate)->format('Y-m-d H:i:s')
                : null;
        }

        $prepuceCondition->fill($data)->save();
        $prepuceCondition->load(['farm', 'livestock', 'conditionType', 'severity', 'causeRisk', 'breedingStatus', 'healingStatus']);

        return response()->json([
            'status' => true,
            'message' => 'Prepuce condition updated successfully',
            'data' => $prepuceCondition,
        ], 200);
    }

    public function adminDestroy(PrepuceCondition $prepuceCondition): JsonResponse
    {
        $prepuceCondition->delete();
        return response()->json([
            'status' => true,
            'message' => 'Prepuce condition deleted successfully',
        ], 200);
    }
}
