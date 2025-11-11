<?php

namespace App\Http\Controllers\Logs\Insemination;

use App\Http\Controllers\Controller;
use App\Models\Insemination;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InseminationController extends Controller
{
    /**
     * Display a listing of insemination logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $inseminations = Insemination::with([
                'livestock',
                'farm',
                'currentHeatType',
                'inseminationService',
                'semenStrawType',
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Insemination logs retrieved successfully',
                'data' => $inseminations,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching insemination logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve insemination logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch insemination logs scoped to farm and livestock UUIDs.
     */
    public function fetchInseminationsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Insemination::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(static function (Insemination $insemination) {
                return [
                    'id' => $insemination->id,
                    'uuid' => $insemination->uuid,
                    'farmUuid' => $insemination->farmUuid,
                    'livestockUuid' => $insemination->livestockUuid,
                    'lastHeatDate' => $insemination->lastHeatDate,
                    'currentHeatTypeId' => $insemination->currentHeatTypeId,
                    'inseminationServiceId' => $insemination->inseminationServiceId,
                    'semenStrawTypeId' => $insemination->semenStrawTypeId,
                    'inseminationDate' => $insemination->inseminationDate,
                    'bullCode' => $insemination->bullCode,
                    'bullBreed' => $insemination->bullBreed,
                    'semenProductionDate' => $insemination->semenProductionDate,
                    'productionCountry' => $insemination->productionCountry,
                    'semenBatchNumber' => $insemination->semenBatchNumber,
                    'internationalId' => $insemination->internationalId,
                    'aiCode' => $insemination->aiCode,
                    'manufacturerName' => $insemination->manufacturerName,
                    'semenSupplier' => $insemination->semenSupplier,
                    'createdAt' => $insemination->created_at?->toIso8601String(),
                    'updatedAt' => $insemination->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process insemination logs from the mobile client.
     */
    public function processInseminations(array $inseminations, string $livestockUuid): array
    {
        $synced = [];

        Log::info('========== PROCESSING INSEMINATIONS START ==========');
        Log::info('Total inseminations to process: ' . count($inseminations));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($inseminations as $payload) {
            $uuid = $payload['uuid'] ?? null;
            $syncAction = $payload['syncAction'] ?? 'create';

            if (!$uuid) {
                Log::warning('⚠️ Insemination entry without UUID skipped', ['payload' => $payload]);
                continue;
            }

            try {
                $timestamps = $this->resolveTimestamps($payload);
                Log::info("Processing insemination: UUID={$uuid}, Action={$syncAction}");

                switch ($syncAction) {
                    case 'create':
                    case 'update':
                        $existing = Insemination::where('uuid', $uuid)->first();

                        if ($existing) {
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Insemination updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Insemination skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Insemination::create(array_merge(
                                ['uuid' => $uuid],
                                $this->mapAttributes($payload, $livestockUuid, $timestamps),
                                ['created_at' => $timestamps['createdAt']->format('Y-m-d H:i:s')]
                            ));
                            Log::info("✅ Insemination created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $existing = Insemination::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Insemination deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Insemination already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for insemination: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING INSEMINATION', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        Log::info('========== PROCESSING INSEMINATIONS END ==========');
        Log::info('Total inseminations synced: ' . count($synced));

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
            'livestockUuid' => $livestockUuid,
            'farmUuid' => $payload['farmUuid'] ?? null,
            'lastHeatDate' => $sanitize($payload['lastHeatDate'] ?? null),
            'currentHeatTypeId' => $payload['currentHeatTypeId'] ?? null,
            'inseminationServiceId' => $payload['inseminationServiceId'] ?? null,
            'semenStrawTypeId' => $payload['semenStrawTypeId'] ?? null,
            'inseminationDate' => $sanitize($payload['inseminationDate'] ?? null),
            'bullCode' => $sanitize($payload['bullCode'] ?? null),
            'bullBreed' => $sanitize($payload['bullBreed'] ?? null),
            'semenProductionDate' => $sanitize($payload['semenProductionDate'] ?? null),
            'productionCountry' => $sanitize($payload['productionCountry'] ?? null),
            'semenBatchNumber' => $sanitize($payload['semenBatchNumber'] ?? null),
            'internationalId' => $sanitize($payload['internationalId'] ?? null),
            'aiCode' => $sanitize($payload['aiCode'] ?? null),
            'manufacturerName' => $sanitize($payload['manufacturerName'] ?? null),
            'semenSupplier' => $sanitize($payload['semenSupplier'] ?? null),
            'updated_at' => $timestamps['updatedAt']->format('Y-m-d H:i:s'),
        ];
    }
}

