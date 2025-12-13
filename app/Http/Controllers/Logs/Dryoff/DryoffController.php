<?php

namespace App\Http\Controllers\Logs\Dryoff;

use App\Http\Controllers\Controller;
use App\Models\Dryoff;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
}

