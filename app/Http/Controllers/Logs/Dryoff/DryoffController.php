<?php

namespace App\Http\Controllers\Logs\Dryoff;

use App\Http\Controllers\Controller;
use App\Models\Dryoff;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DryoffController extends Controller
{
    use ConvertsDateFormat;
    /**
     * Display a listing of dryoff logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $dryoffs = Dryoff::with(['livestock', 'farm'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Dryoff logs retrieved successfully',
                'data' => $dryoffs,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching dryoff logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve dryoff logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch dryoff logs scoped to farm and livestock UUIDs.
     */
    public function fetchDryoffsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Dryoff::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(static function (Dryoff $dryoff) {
                return [
                    'id' => $dryoff->id,
                    'uuid' => $dryoff->uuid,
                    'farmUuid' => $dryoff->farmUuid,
                    'livestockUuid' => $dryoff->livestockUuid,
                    'startDate' => $dryoff->startDate,
                    'endDate' => $dryoff->endDate,
                    'reason' => $dryoff->reason,
                    'remarks' => $dryoff->remarks,
                    'createdAt' => $dryoff->created_at?->toIso8601String(),
                    'updatedAt' => $dryoff->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process dryoff logs from the mobile client.
     */
    public function processDryoffs(array $dryoffs, string $livestockUuid): array
    {
        $synced = [];

        Log::info('========== PROCESSING DRYOFFS START ==========');
        Log::info('Total dryoffs to process: ' . count($dryoffs));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($dryoffs as $payload) {
            $uuid = $payload['uuid'] ?? null;
            $syncAction = $payload['syncAction'] ?? 'create';

            if (!$uuid) {
                Log::warning('⚠️ Dryoff entry without UUID skipped', ['payload' => $payload]);
                continue;
            }

            try {
                $timestamps = $this->resolveTimestamps($payload);
                Log::info("Processing dryoff: UUID={$uuid}, Action={$syncAction}");

                switch ($syncAction) {
                    case 'create':
                    case 'update':
                        $existing = Dryoff::where('uuid', $uuid)->first();

                        if ($existing) {
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Dryoff updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Dryoff skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Dryoff::create(array_merge(
                                ['uuid' => $uuid],
                                $this->mapAttributes($payload, $livestockUuid, $timestamps),
                                ['created_at' => $timestamps['createdAt']->format('Y-m-d H:i:s')]
                            ));
                            Log::info("✅ Dryoff created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $existing = Dryoff::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Dryoff deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Dryoff already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for dryoff: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING DRYOFF', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        Log::info('========== PROCESSING DRYOFFS END ==========');
        Log::info('Total dryoffs synced: ' . count($synced));

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

        // Use trait method for date conversion instead of local formatDate function

        return [
            'farmUuid' => $payload['farmUuid'] ?? null,
            'livestockUuid' => $livestockUuid,
            'startDate' => $this->convertDateFormat($sanitize($payload['startDate'] ?? null)),
            'endDate' => $this->convertDateFormat($sanitize($payload['endDate'] ?? null)),
            'reason' => $sanitize($payload['reason'] ?? null),
            'remarks' => $sanitize($payload['remarks'] ?? null),
            'updated_at' => $timestamps['updatedAt']->format('Y-m-d H:i:s'),
        ];
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $dryoffs = Dryoff::with(['livestock', 'farm'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Dryoffs retrieved successfully',
            'data' => $dryoffs,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:dryoffs,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestock,uuid',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date',
            'reason' => 'nullable|string',
            'remarks' => 'nullable|string',
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

        $dryoff = Dryoff::create($data);

        $dryoff->load(['livestock', 'farm']);

        return response()->json([
            'status' => true,
            'message' => 'Dryoff created successfully',
            'data' => $dryoff,
        ], 201);
    }

    public function adminShow(Dryoff $dryoff): JsonResponse
    {
        $dryoff->load(['livestock', 'farm']);

        return response()->json([
            'status' => true,
            'message' => 'Dryoff retrieved successfully',
            'data' => $dryoff,
        ], 200);
    }

    public function adminUpdate(Request $request, Dryoff $dryoff): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:dryoffs,uuid,' . $dryoff->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestock,uuid',
            'startDate' => 'sometimes|required|date',
            'endDate' => 'sometimes|nullable|date',
            'reason' => 'sometimes|nullable|string',
            'remarks' => 'sometimes|nullable|string',
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

        $dryoff->fill($data);
        $dryoff->save();

        $dryoff->load(['livestock', 'farm']);

        return response()->json([
            'status' => true,
            'message' => 'Dryoff updated successfully',
            'data' => $dryoff,
        ], 200);
    }

    public function adminDestroy(Dryoff $dryoff): JsonResponse
    {
        $dryoff->delete();

        return response()->json([
            'status' => true,
            'message' => 'Dryoff deleted successfully',
        ], 200);
    }
}

