<?php

namespace App\Http\Controllers\Logs\Calving;

use App\Http\Controllers\Controller;
use App\Models\Calving;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CalvingController extends Controller
{
    use ConvertsDateFormat;
    /**
     * Display a listing of calving logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $calvings = Calving::with([
                'livestock',
                'farm',
                'calvingType',
                'calvingProblem',
                'reproductiveProblem',
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Calving logs retrieved successfully',
                'data' => $calvings,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching calving logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve calving logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch calving logs scoped to farm and livestock UUIDs.
     */
    public function fetchCalvingsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Calving::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(static function (Calving $calving) {
                return [
                    'id' => $calving->id,
                    'uuid' => $calving->uuid,
                    'farmUuid' => $calving->farmUuid,
                    'livestockUuid' => $calving->livestockUuid,
                    'startDate' => $calving->startDate,
                    'endDate' => $calving->endDate,
                    'calvingTypeId' => $calving->calvingTypeId,
                    'calvingProblemsId' => $calving->calvingProblemsId,
                    'reproductiveProblemId' => $calving->reproductiveProblemId,
                    'remarks' => $calving->remarks,
                    'status' => $calving->status,
                    'createdAt' => $calving->created_at?->toIso8601String(),
                    'updatedAt' => $calving->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process calving logs from the mobile client.
     */
    public function processCalvings(array $calvings, string $livestockUuid): array
    {
        $synced = [];

        Log::info('========== PROCESSING CALVINGS START ==========');
        Log::info('Total calvings to process: ' . count($calvings));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($calvings as $payload) {
            $uuid = $payload['uuid'] ?? null;
            $syncAction = $payload['syncAction'] ?? 'create';

            if (!$uuid) {
                Log::warning('⚠️ Calving entry without UUID skipped', ['payload' => $payload]);
                continue;
            }

            try {
                $timestamps = $this->resolveTimestamps($payload);
                Log::info("Processing calving: UUID={$uuid}, Action={$syncAction}");

                switch ($syncAction) {
                    case 'create':
                    case 'update':
                        $existing = Calving::where('uuid', $uuid)->first();

                        if ($existing) {
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Calving updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Calving skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Calving::create(array_merge(
                                ['uuid' => $uuid],
                                $this->mapAttributes($payload, $livestockUuid, $timestamps),
                                ['created_at' => $timestamps['createdAt']->format('Y-m-d H:i:s')]
                            ));
                            Log::info("✅ Calving created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $existing = Calving::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Calving deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Calving already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for calving: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING CALVING', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        Log::info('========== PROCESSING CALVINGS END ==========');
        Log::info('Total calvings synced: ' . count($synced));

        return $synced;
    }

    private function resolveTimestamps(array $payload): array
    {
        return [
            'createdAt' => isset($payload['createdAt'])
                ? Carbon::parse($payload['createdAt'])
                : now(),
            'updatedAt' => isset($payload['updatedAt'])
                ? Carbon::parse($payload['updatedAt'])
                : now(),
        ];
    }

    private function mapAttributes(array $payload, string $livestockUuid, array $timestamps): array
    {
        $sanitize = static function ($value) {
            if (!isset($value)) {
                return null;
            }

            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        };

        return [
            'farmUuid' => $payload['farmUuid'] ?? null,
            'livestockUuid' => $livestockUuid,
            'startDate' => $this->convertDateFormat($sanitize($payload['startDate'] ?? null)),
            'endDate' => $this->convertDateFormat($sanitize($payload['endDate'] ?? null)),
            'calvingTypeId' => $payload['calvingTypeId'] ?? null,
            'calvingProblemsId' => $payload['calvingProblemsId'] ?? null,
            'reproductiveProblemId' => $payload['reproductiveProblemId'] ?? null,
            'remarks' => $sanitize($payload['remarks'] ?? null),
            'status' => $payload['status'] ?? 'active',
            'updated_at' => $timestamps['updatedAt']->format('Y-m-d H:i:s'),
        ];
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $calvings = Calving::with([
            'livestock',
            'farm',
            'calvingType',
            'calvingProblem',
            'reproductiveProblem',
        ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Calvings retrieved successfully',
            'data' => $calvings,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:calvings,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestock,uuid',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date',
            'calvingTypeId' => 'nullable|integer|exists:birth_types,id',
            'calvingProblemsId' => 'nullable|integer|exists:birth_problems,id',
            'reproductiveProblemId' => 'nullable|integer|exists:reproductive_problems,id',
            'remarks' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        $data['startDate'] = $this->convertDateFormat($request->startDate);
        if ($request->has('endDate')) {
            $data['endDate'] = $this->convertDateFormat($request->endDate);
        }

        $calving = Calving::create($data);

        $calving->load([
            'livestock',
            'farm',
            'calvingType',
            'calvingProblem',
            'reproductiveProblem',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Calving created successfully',
            'data' => $calving,
        ], 201);
    }

    public function adminShow(Calving $calving): JsonResponse
    {
        $calving->load([
            'livestock',
            'farm',
            'calvingType',
            'calvingProblem',
            'reproductiveProblem',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Calving retrieved successfully',
            'data' => $calving,
        ], 200);
    }

    public function adminUpdate(Request $request, Calving $calving): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:calvings,uuid,' . $calving->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestock,uuid',
            'startDate' => 'sometimes|required|date',
            'endDate' => 'sometimes|nullable|date',
            'calvingTypeId' => 'sometimes|nullable|integer|exists:birth_types,id',
            'calvingProblemsId' => 'sometimes|nullable|integer|exists:birth_problems,id',
            'reproductiveProblemId' => 'sometimes|nullable|integer|exists:reproductive_problems,id',
            'remarks' => 'sometimes|nullable|string',
            'status' => 'sometimes|nullable|string|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['startDate', 'endDate']);

        if ($request->has('startDate')) {
            $data['startDate'] = $this->convertDateFormat($request->startDate);
        }
        if ($request->has('endDate')) {
            $data['endDate'] = $request->endDate ? $this->convertDateFormat($request->endDate) : null;
        }

        $calving->fill($data);
        $calving->save();

        $calving->load([
            'livestock',
            'farm',
            'calvingType',
            'calvingProblem',
            'reproductiveProblem',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Calving updated successfully',
            'data' => $calving,
        ], 200);
    }

    public function adminDestroy(Calving $calving): JsonResponse
    {
        $calving->delete();

        return response()->json([
            'status' => true,
            'message' => 'Calving deleted successfully',
        ], 200);
    }
}

