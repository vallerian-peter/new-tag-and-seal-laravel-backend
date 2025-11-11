<?php

namespace App\Http\Controllers\Logs\Pregnancy;

use App\Http\Controllers\Controller;
use App\Models\Pregnancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PregnancyController extends Controller
{
    /**
     * Display a listing of pregnancy logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $pregnancies = Pregnancy::with(['livestock', 'farm', 'testResult'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Pregnancy logs retrieved successfully',
                'data' => $pregnancies,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching pregnancy logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve pregnancy logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch pregnancy logs scoped to farm and livestock UUIDs.
     */
    public function fetchPregnanciesWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Pregnancy::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(static function (Pregnancy $pregnancy) {
                return [
                    'id' => $pregnancy->id,
                    'uuid' => $pregnancy->uuid,
                    'farmUuid' => $pregnancy->farmUuid,
                    'livestockUuid' => $pregnancy->livestockUuid,
                    'testResultId' => $pregnancy->testResultId,
                    'noOfMonths' => $pregnancy->noOfMonths,
                    'testDate' => $pregnancy->testDate,
                    'status' => $pregnancy->status,
                    'remarks' => $pregnancy->remarks,
                    'createdAt' => $pregnancy->created_at?->toIso8601String(),
                    'updatedAt' => $pregnancy->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process pregnancy logs from the mobile client.
     */
    public function processPregnancies(array $pregnancies, string $livestockUuid): array
    {
        $synced = [];

        Log::info('========== PROCESSING PREGNANCIES START ==========');
        Log::info('Total pregnancies to process: ' . count($pregnancies));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($pregnancies as $payload) {
            $uuid = $payload['uuid'] ?? null;
            $syncAction = $payload['syncAction'] ?? 'create';

            if (!$uuid) {
                Log::warning('⚠️ Pregnancy entry without UUID skipped', ['payload' => $payload]);
                continue;
            }

            try {
                $timestamps = $this->resolveTimestamps($payload);
                Log::info("Processing pregnancy: UUID={$uuid}, Action={$syncAction}");

                switch ($syncAction) {
                    case 'create':
                    case 'update':
                        $existing = Pregnancy::where('uuid', $uuid)->first();

                        if ($existing) {
                            if ($timestamps['updatedAt']->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update($this->mapAttributes($payload, $livestockUuid, $timestamps));
                                Log::info("✅ Pregnancy updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Pregnancy skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Pregnancy::create(array_merge(
                                ['uuid' => $uuid],
                                $this->mapAttributes($payload, $livestockUuid, $timestamps),
                                ['created_at' => $timestamps['createdAt']->format('Y-m-d H:i:s')]
                            ));
                            Log::info("✅ Pregnancy created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $existing = Pregnancy::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Pregnancy deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Pregnancy already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for pregnancy: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING PREGNANCY', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        Log::info('========== PROCESSING PREGNANCIES END ==========');
        Log::info('Total pregnancies synced: ' . count($synced));

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
            'testResultId' => $payload['testResultId'] ?? null,
            'noOfMonths' => $sanitize($payload['noOfMonths'] ?? null),
            'testDate' => $sanitize($payload['testDate'] ?? null),
            'status' => $payload['status'] ?? 'active',
            'remarks' => $sanitize($payload['remarks'] ?? null),
            'updated_at' => $timestamps['updatedAt']->format('Y-m-d H:i:s'),
        ];
    }
}

