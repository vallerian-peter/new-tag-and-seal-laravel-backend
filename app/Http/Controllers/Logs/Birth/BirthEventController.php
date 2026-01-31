<?php

namespace App\Http\Controllers\Logs\Birth;

use App\Http\Controllers\Controller;
use App\Models\BirthEvent;
use App\Models\Livestock;
use App\Models\Specie;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BirthEventController extends Controller
{
    use ConvertsDateFormat;
    /**
     * Display a listing of birth event logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $birthEvents = BirthEvent::with([
                'livestock',
                'farm',
                'birthType',
                'birthProblem',
                'reproductiveProblem',
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Birth events retrieved successfully',
                'data' => $birthEvents,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching birth events: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve birth events',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created birth event.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $livestock = Livestock::where('uuid', $request->livestockUuid)->first();

            if (!$livestock) {
                return response()->json([
                    'status' => false,
                    'message' => 'Livestock not found',
                ], 404);
            }

            // Determine eventType based on livestock species
            $species = Specie::find($livestock->speciesId);
            $eventType = strtolower($species->name) === 'pig' ? 'farrowing' : 'calving';

            $birthEvent = BirthEvent::create([
                'uuid' => $request->uuid ?? Str::uuid()->toString(),
                'farmUuid' => $request->farmUuid,
                'livestockUuid' => $request->livestockUuid,
                'eventType' => $eventType,
                'startDate' => $this->convertDateFormat($request->startDate),
                'endDate' => $this->convertDateFormat($request->endDate),
                'birthTypeId' => $request->birthTypeId ?? $request->calvingTypeId ?? null,
                'birthProblemsId' => $request->birthProblemsId ?? $request->calvingProblemsId ?? null,
                'reproductiveProblemId' => $request->reproductiveProblemId ?? null,
                'remarks' => $request->remarks ?? null,
                'status' => $request->status ?? 'active',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Birth event created successfully',
                'data' => $birthEvent->load(['livestock', 'farm', 'birthType', 'birthProblem', 'reproductiveProblem']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating birth event: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to create birth event',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch birth event logs scoped to farm and livestock UUIDs.
     */
    public function fetchBirthEventsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return BirthEvent::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(static function (BirthEvent $birthEvent) {
                return [
                    'id' => $birthEvent->id,
                    'uuid' => $birthEvent->uuid,
                    'farmUuid' => $birthEvent->farmUuid,
                    'livestockUuid' => $birthEvent->livestockUuid,
                    'eventType' => $birthEvent->eventType,
                    'startDate' => $birthEvent->startDate,
                    'endDate' => $birthEvent->endDate,
                    'birthTypeId' => $birthEvent->birthTypeId,
                    'birthProblemsId' => $birthEvent->birthProblemsId,
                    'calvingTypeId' => $birthEvent->birthTypeId, // Backward compatibility
                    'calvingProblemsId' => $birthEvent->birthProblemsId, // Backward compatibility
                    'reproductiveProblemId' => $birthEvent->reproductiveProblemId,
                    'remarks' => $birthEvent->remarks,
                    'status' => $birthEvent->status,
                    'eventDate' => $birthEvent->eventDate ? Carbon::parse($birthEvent->eventDate)->toIso8601String() : $birthEvent->created_at?->toIso8601String(),
                    'createdAt' => $birthEvent->created_at?->toIso8601String(),
                    'updatedAt' => $birthEvent->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process birth event logs from the mobile client.
     */
    public function processBirthEvents(array $birthEvents, string $livestockUuid): array
    {
        $synced = [];

        Log::info('========== PROCESSING BIRTH EVENTS START ==========');
        Log::info('Total birth events to process: ' . count($birthEvents));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($birthEvents as $payload) {
            $uuid = $payload['uuid'] ?? null;
            $syncAction = $payload['syncAction'] ?? 'create';

            if (!$uuid) {
                Log::warning('⚠️ Birth event entry without UUID skipped', ['payload' => $payload]);
                continue;
            }

            try {
                $timestamps = $this->resolveTimestamps($payload);
                Log::info("Processing birth event: UUID={$uuid}, Action={$syncAction}");

                switch ($syncAction) {
                    case 'create':
                        $existing = BirthEvent::where('uuid', $uuid)->first();

                        if ($existing) {
                            // Treat create as upsert: update if local is newer
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Birth event updated via create (upsert): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Birth event create skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            BirthEvent::create(array_merge(
                                ['uuid' => $uuid],
                                $this->mapAttributes($payload, $livestockUuid, $timestamps),
                                ['created_at' => $timestamps['createdAt']->format('Y-m-d H:i:s')]
                            ));
                            Log::info("✅ Birth event created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $existing = BirthEvent::where('uuid', $uuid)->first();

                        if ($existing) {
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Birth event updated: UUID {$uuid}");
                                $synced[] = ['uuid' => $uuid];
                            } else {
                                Log::info("⏭️ Birth event update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Birth event not found for update: UUID {$uuid}");
                        }
                        break;

                    case 'deleted':
                        $existing = BirthEvent::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Birth event deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Birth event already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for birth event: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING BIRTH EVENT', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        Log::info('========== PROCESSING BIRTH EVENTS END ==========');
        Log::info('Total birth events synced: ' . count($synced));

        return $synced;
    }

    private function resolveTimestamps(array $payload): array
    {
        $createdAt = isset($payload['createdAt'])
            ? Carbon::parse($payload['createdAt'])
            : now();

        return [
            'createdAt' => $createdAt,
            'updatedAt' => isset($payload['updatedAt'])
                ? Carbon::parse($payload['updatedAt'])
                : now(),
            'eventDate' => isset($payload['eventDate'])
                ? Carbon::parse($payload['eventDate'])
                : $createdAt,
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

        // Determine eventType if not provided
        $eventType = $payload['eventType'] ?? null;
        if (!$eventType) {
            $livestock = Livestock::where('uuid', $livestockUuid)->first();
            if ($livestock) {
                $species = Specie::find($livestock->speciesId);
                $eventType = strtolower($species->name) === 'pig' ? 'farrowing' : 'calving';
            } else {
                $eventType = 'calving'; // Default
            }
        }

        return [
            'farmUuid' => $payload['farmUuid'] ?? null,
            'livestockUuid' => $livestockUuid,
            'eventType' => $eventType,
            'startDate' => $this->convertDateFormat($sanitize($payload['startDate'] ?? null)),
            'endDate' => $this->convertDateFormat($sanitize($payload['endDate'] ?? null)),
            'birthTypeId' => $payload['birthTypeId'] ?? $payload['calvingTypeId'] ?? null,
            'birthProblemsId' => $payload['birthProblemsId'] ?? $payload['calvingProblemsId'] ?? null,
            'reproductiveProblemId' => $payload['reproductiveProblemId'] ?? null,
            'remarks' => $sanitize($payload['remarks'] ?? null),
            'status' => $payload['status'] ?? 'active',
            'eventDate' => $timestamps['eventDate']->format('Y-m-d H:i:s'),
            'updated_at' => $timestamps['updatedAt']->format('Y-m-d H:i:s'),
        ];
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $birthEvents = BirthEvent::with([
            'livestock',
            'farm',
            'birthType',
            'birthProblem',
            'reproductiveProblem',
        ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Birth events retrieved successfully',
            'data' => $birthEvents,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'nullable|string|unique:birth_events,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'eventType' => 'nullable|string|in:calving,farrowing',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date',
            'birthTypeId' => 'nullable|integer|exists:birth_types,id',
            'birthProblemsId' => 'nullable|integer|exists:birth_problems,id',
            'reproductiveProblemId' => 'nullable|integer|exists:reproductive_problems,id',
            'remarks' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
            'eventDate' => 'nullable|date',
        ]);

        $data = $request->all();
        if (empty($data['uuid'])) {
            $data['uuid'] = (string) Str::uuid();
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Auto-determine eventType if not provided
        if (!$request->has('eventType')) {
            $livestock = Livestock::where('uuid', $request->livestockUuid)->first();
            if ($livestock) {
                $species = Specie::find($livestock->speciesId);
                $eventType = $species && strtolower($species->name) === 'pig' ? 'farrowing' : 'calving';
            } else {
                $eventType = 'calving';
            }
        } else {
            $eventType = $request->eventType;
        }

        $data = $request->all();
        $data['eventType'] = $eventType;
        $data['startDate'] = $this->convertDateFormat($request->startDate);
        $data['endDate'] = $request->endDate ? $this->convertDateFormat($request->endDate) : null;
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        } else {
            // Default to now if not provided
            $data['eventDate'] = now()->format('Y-m-d H:i:s');
        }

        $birthEvent = BirthEvent::create($data);

        $birthEvent->load([
            'livestock',
            'farm',
            'birthType',
            'birthProblem',
            'reproductiveProblem',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Birth event created successfully',
            'data' => $birthEvent,
        ], 201);
    }

    public function adminShow(BirthEvent $birthEvent): JsonResponse
    {
        $birthEvent->load([
            'livestock',
            'farm',
            'birthType',
            'birthProblem',
            'reproductiveProblem',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Birth event retrieved successfully',
            'data' => $birthEvent,
        ], 200);
    }

    public function adminUpdate(Request $request, BirthEvent $birthEvent): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:birth_events,uuid,' . $birthEvent->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'eventType' => 'sometimes|nullable|string|in:calving,farrowing',
            'startDate' => 'sometimes|required|date',
            'endDate' => 'sometimes|nullable|date',
            'birthTypeId' => 'sometimes|nullable|integer|exists:birth_types,id',
            'birthProblemsId' => 'sometimes|nullable|integer|exists:birth_problems,id',
            'reproductiveProblemId' => 'sometimes|nullable|integer|exists:reproductive_problems,id',
            'remarks' => 'sometimes|nullable|string',
            'status' => 'sometimes|nullable|string|in:active,inactive',
            'eventDate' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['startDate', 'endDate', 'eventDate']);

        if ($request->has('startDate')) {
            $data['startDate'] = $this->convertDateFormat($request->startDate);
        }
        if ($request->has('endDate')) {
            $data['endDate'] = $request->endDate ? $this->convertDateFormat($request->endDate) : null;
        }
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        }

        $birthEvent->fill($data);
        $birthEvent->save();

        $birthEvent->load([
            'livestock',
            'farm',
            'birthType',
            'birthProblem',
            'reproductiveProblem',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Birth event updated successfully',
            'data' => $birthEvent,
        ], 200);
    }

    public function adminDestroy(BirthEvent $birthEvent): JsonResponse
    {
        $birthEvent->delete();

        return response()->json([
            'status' => true,
            'message' => 'Birth event deleted successfully',
        ], 200);
    }
}

