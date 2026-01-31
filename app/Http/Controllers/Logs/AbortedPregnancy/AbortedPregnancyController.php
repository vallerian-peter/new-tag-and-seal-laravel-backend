<?php

namespace App\Http\Controllers\Logs\AbortedPregnancy;

use App\Http\Controllers\Controller;
use App\Models\AbortedPregnancy;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AbortedPregnancyController extends Controller
{
    use ConvertsDateFormat;
    /**
     * Display a listing of aborted pregnancy logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $abortedPregnancies = AbortedPregnancy::with([
                'livestock',
                'farm',
                'reproductiveProblem',
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Aborted pregnancies retrieved successfully',
                'data' => $abortedPregnancies,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching aborted pregnancies: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve aborted pregnancies',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch aborted pregnancy logs scoped to farm and livestock UUIDs.
     */
    public function fetchAbortedPregnanciesWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return AbortedPregnancy::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(static function (AbortedPregnancy $abortedPregnancy) {
                return [
                    'id' => $abortedPregnancy->id,
                    'uuid' => $abortedPregnancy->uuid,
                    'farmUuid' => $abortedPregnancy->farmUuid,
                    'livestockUuid' => $abortedPregnancy->livestockUuid,
                    'abortionDate' => $abortedPregnancy->abortionDate,
                    'reproductiveProblemId' => $abortedPregnancy->reproductiveProblemId,
                    'remarks' => $abortedPregnancy->remarks,
                    'status' => $abortedPregnancy->status,
                    'eventDate' => $abortedPregnancy->eventDate ? Carbon::parse($abortedPregnancy->eventDate)->toIso8601String() : $abortedPregnancy->created_at?->toIso8601String(),
                    'createdAt' => $abortedPregnancy->created_at?->toIso8601String(),
                    'updatedAt' => $abortedPregnancy->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Store a newly created aborted pregnancy.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $abortedPregnancy = AbortedPregnancy::create([
                'uuid' => $request->uuid ?? Str::uuid()->toString(),
                'farmUuid' => $request->farmUuid,
                'livestockUuid' => $request->livestockUuid,
                'abortionDate' => $this->convertDateFormat($request->abortionDate),
                'reproductiveProblemId' => $request->reproductiveProblemId ?? null,
                'remarks' => $request->remarks ?? null,
                'status' => $request->status ?? 'active',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Aborted pregnancy created successfully',
                'data' => $abortedPregnancy->load(['livestock', 'farm', 'reproductiveProblem']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating aborted pregnancy: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to create aborted pregnancy',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process aborted pregnancy logs from the mobile client.
     */
    public function processAbortedPregnancies(array $abortedPregnancies, string $livestockUuid): array
    {
        $synced = [];

        Log::info('========== PROCESSING ABORTED PREGNANCIES START ==========');
        Log::info('Total aborted pregnancies to process: ' . count($abortedPregnancies));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($abortedPregnancies as $payload) {
            $uuid = $payload['uuid'] ?? null;
            $syncAction = $payload['syncAction'] ?? 'create';

            if (!$uuid) {
                Log::warning('⚠️ Aborted pregnancy entry without UUID skipped', ['payload' => $payload]);
                continue;
            }

            try {
                $timestamps = $this->resolveTimestamps($payload);
                Log::info("Processing aborted pregnancy: UUID={$uuid}, Action={$syncAction}");

                switch ($syncAction) {
                    case 'create':
                        $existing = AbortedPregnancy::where('uuid', $uuid)->first();

                        if ($existing) {
                            // Treat create as upsert: update if local is newer
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Aborted pregnancy updated via create (upsert): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Aborted pregnancy create skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            AbortedPregnancy::create(array_merge(
                                ['uuid' => $uuid],
                                $this->mapAttributes($payload, $livestockUuid, $timestamps),
                                ['created_at' => $timestamps['createdAt']->format('Y-m-d H:i:s')]
                            ));
                            Log::info("✅ Aborted pregnancy created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $existing = AbortedPregnancy::where('uuid', $uuid)->first();

                        if ($existing) {
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Aborted pregnancy updated: UUID {$uuid}");
                                $synced[] = ['uuid' => $uuid];
                            } else {
                                Log::info("⏭️ Aborted pregnancy update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Aborted pregnancy not found for update: UUID {$uuid}");
                        }
                        break;

                    case 'deleted':
                        $existing = AbortedPregnancy::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Aborted pregnancy deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Aborted pregnancy already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for aborted pregnancy: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING ABORTED PREGNANCY', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        Log::info('========== PROCESSING ABORTED PREGNANCIES END ==========');
        Log::info('Total aborted pregnancies synced: ' . count($synced));

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

        return [
            'farmUuid' => $payload['farmUuid'] ?? null,
            'livestockUuid' => $livestockUuid,
            'abortionDate' => $this->convertDateFormat($sanitize($payload['abortionDate'] ?? null)),
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
        $abortedPregnancies = AbortedPregnancy::with([
            'livestock',
            'farm',
            'reproductiveProblem',
        ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Aborted pregnancies retrieved successfully',
            'data' => $abortedPregnancies,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:aborted_pregnancies,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'abortionDate' => 'required|date',
            'reproductiveProblemId' => 'nullable|integer|exists:reproductive_problems,id',
            'remarks' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
            'eventDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        $data['abortionDate'] = $this->convertDateFormat($request->abortionDate);
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        } else {
            // Default to now if not provided
            $data['eventDate'] = now()->format('Y-m-d H:i:s');
        }

        $abortedPregnancy = AbortedPregnancy::create($data);

        $abortedPregnancy->load(['livestock', 'farm', 'reproductiveProblem']);

        return response()->json([
            'status' => true,
            'message' => 'Aborted pregnancy created successfully',
            'data' => $abortedPregnancy,
        ], 201);
    }

    public function adminShow(AbortedPregnancy $abortedPregnancy): JsonResponse
    {
        $abortedPregnancy->load(['livestock', 'farm', 'reproductiveProblem']);

        return response()->json([
            'status' => true,
            'message' => 'Aborted pregnancy retrieved successfully',
            'data' => $abortedPregnancy,
        ], 200);
    }

    public function adminUpdate(Request $request, AbortedPregnancy $abortedPregnancy): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:aborted_pregnancies,uuid,' . $abortedPregnancy->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'abortionDate' => 'sometimes|required|date',
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

        $data = $request->except(['abortionDate', 'eventDate']);
        
        if ($request->has('abortionDate')) {
            $data['abortionDate'] = $this->convertDateFormat($request->abortionDate);
        }
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        }

        $abortedPregnancy->fill($data);
        $abortedPregnancy->save();

        $abortedPregnancy->load(['livestock', 'farm', 'reproductiveProblem']);

        return response()->json([
            'status' => true,
            'message' => 'Aborted pregnancy updated successfully',
            'data' => $abortedPregnancy,
        ], 200);
    }

    public function adminDestroy(AbortedPregnancy $abortedPregnancy): JsonResponse
    {
        $abortedPregnancy->delete();

        return response()->json([
            'status' => true,
            'message' => 'Aborted pregnancy deleted successfully',
        ], 200);
    }
}

