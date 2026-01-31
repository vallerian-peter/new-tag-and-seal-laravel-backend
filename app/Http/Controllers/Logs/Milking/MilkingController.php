<?php

namespace App\Http\Controllers\Logs\Milking;

use App\Http\Controllers\Controller;
use App\Models\Milking;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MilkingController extends Controller
{
    /**
     * Display a listing of milking logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $milkings = Milking::with(['livestock', 'farm', 'milkingMethod'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Milking logs retrieved successfully',
                'data' => $milkings,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching milking logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve milking logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch milking logs scoped to farm and livestock UUIDs.
     */
    public function fetchMilkingsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Milking::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(static function (Milking $milking) {
                return [
                    'id' => $milking->id,
                    'uuid' => $milking->uuid,
                    'farmUuid' => $milking->farmUuid,
                    'livestockUuid' => $milking->livestockUuid,
                    'milkingMethodId' => $milking->milkingMethodId,
                    'amount' => $milking->amount,
                    'lactometerReading' => $milking->lactometerReading,
                    'solid' => $milking->solid,
                    'solidNonFat' => $milking->solidNonFat,
                    'protein' => $milking->protein,
                    'correctedLactometerReading' => $milking->correctedLactometerReading,
                    'totalSolids' => $milking->totalSolids,
                    'colonyFormingUnits' => $milking->colonyFormingUnits,
                    'acidity' => $milking->acidity,
                    'session' => $milking->session,
                    'status' => $milking->status,
                    'eventDate' => $milking->eventDate ? Carbon::parse($milking->eventDate)->toIso8601String() : $milking->created_at?->toIso8601String(),
                    'createdAt' => $milking->created_at?->toIso8601String(),
                    'updatedAt' => $milking->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process milking logs received from the mobile app.
     */
    public function processMilkings(array $milkings, string $livestockUuid): array
    {
        $synced = [];

        Log::info('========== PROCESSING MILKINGS START ==========');
        Log::info('Total milkings to process: ' . count($milkings));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($milkings as $payload) {
            $uuid = $payload['uuid'] ?? null;
            $syncAction = $payload['syncAction'] ?? 'create';

            if (!$uuid) {
                Log::warning('⚠️ Milking entry without UUID skipped', ['payload' => $payload]);
                continue;
            }

            try {
                $timestamps = $this->resolveTimestamps($payload);
                Log::info("Processing milking: UUID={$uuid}, Action={$syncAction}");

                switch ($syncAction) {
                    case 'create':
                    case 'update':
                        $existing = Milking::where('uuid', $uuid)->first();

                        if ($existing) {
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Milking updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Milking skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Milking::create(array_merge(
                                ['uuid' => $uuid],
                                $this->mapAttributes($payload, $livestockUuid, $timestamps),
                                ['created_at' => $timestamps['createdAt']->format('Y-m-d H:i:s')]
                            ));
                            Log::info("✅ Milking created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $existing = Milking::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Milking deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Milking already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for milking: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING MILKING', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        Log::info('========== PROCESSING MILKINGS END ==========');
        Log::info('Total milkings synced: ' . count($synced));

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
            'livestockUuid' => $livestockUuid,
            'farmUuid' => $payload['farmUuid'] ?? null,
            'milkingMethodId' => $payload['milkingMethodId'] ?? null,
            'amount' => $sanitize($payload['amount'] ?? null),
            'lactometerReading' => $sanitize($payload['lactometerReading'] ?? null),
            'solid' => $sanitize($payload['solid'] ?? null),
            'solidNonFat' => $sanitize($payload['solidNonFat'] ?? null),
            'protein' => $sanitize($payload['protein'] ?? null),
            'correctedLactometerReading' => $sanitize($payload['correctedLactometerReading'] ?? null),
            'totalSolids' => $sanitize($payload['totalSolids'] ?? null),
            'colonyFormingUnits' => $sanitize($payload['colonyFormingUnits'] ?? null),
            'acidity' => $sanitize($payload['acidity'] ?? null),
            'session' => $payload['session'] ?? 'morning',
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
        $milkings = Milking::with(['livestock', 'farm', 'milkingMethod'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Milkings retrieved successfully',
            'data' => $milkings,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'nullable|string|unique:milkings,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'milkingMethodId' => 'nullable|integer|exists:milking_methods,id',
            'amount' => 'nullable|string|max:255',
            'lactometerReading' => 'nullable|string|max:255',
            'solid' => 'nullable|string|max:255',
            'solidNonFat' => 'nullable|string|max:255',
            'protein' => 'nullable|string|max:255',
            'correctedLactometerReading' => 'nullable|string|max:255',
            'totalSolids' => 'nullable|string|max:255',
            'colonyFormingUnits' => 'nullable|string|max:255',
            'acidity' => 'nullable|string|max:255',
            'session' => 'nullable|string|in:morning,evening,night,midnight',
            'status' => 'nullable|string|in:active,inactive,pending',
            'eventDate' => 'nullable|date',
        ]);

        $data = $request->all();
        if (empty($data['uuid'])) {
            $data['uuid'] = (string) \Illuminate\Support\Str::uuid();
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        } else {
            // Default to now if not provided
            $data['eventDate'] = now()->format('Y-m-d H:i:s');
        }

        $milking = Milking::create($data);

        $milking->load(['livestock', 'farm', 'milkingMethod']);

        return response()->json([
            'status' => true,
            'message' => 'Milking created successfully',
            'data' => $milking,
        ], 201);
    }

    public function adminShow(Milking $milking): JsonResponse
    {
        $milking->load(['livestock', 'farm', 'milkingMethod']);

        return response()->json([
            'status' => true,
            'message' => 'Milking retrieved successfully',
            'data' => $milking,
        ], 200);
    }

    public function adminUpdate(Request $request, Milking $milking): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:milkings,uuid,' . $milking->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'milkingMethodId' => 'sometimes|nullable|integer|exists:milking_methods,id',
            'amount' => 'sometimes|nullable|string|max:255',
            'lactometerReading' => 'sometimes|nullable|string|max:255',
            'solid' => 'sometimes|nullable|string|max:255',
            'solidNonFat' => 'sometimes|nullable|string|max:255',
            'protein' => 'sometimes|nullable|string|max:255',
            'correctedLactometerReading' => 'sometimes|nullable|string|max:255',
            'totalSolids' => 'sometimes|nullable|string|max:255',
            'colonyFormingUnits' => 'sometimes|nullable|string|max:255',
            'acidity' => 'sometimes|nullable|string|max:255',
            'session' => 'sometimes|nullable|string|in:morning,evening',
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

        $data = $request->except(['eventDate']);
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        }

        $milking->fill($data);
        $milking->save();

        $milking->load(['livestock', 'farm', 'milkingMethod']);

        return response()->json([
            'status' => true,
            'message' => 'Milking updated successfully',
            'data' => $milking,
        ], 200);
    }

    public function adminDestroy(Milking $milking): JsonResponse
    {
        $milking->delete();

        return response()->json([
            'status' => true,
            'message' => 'Milking deleted successfully',
        ], 200);
    }
}

