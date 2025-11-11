<?php

namespace App\Http\Controllers\Logs\Vaccination;

use App\Http\Controllers\Controller;
use App\Models\Vaccination;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VaccinationController extends Controller
{
    /**
     * Display a listing of vaccinations.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $vaccinations = Vaccination::with(['livestock', 'farm', 'vaccine', 'disease', 'vet', 'extensionOfficer'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Vaccination logs retrieved successfully',
                'data' => $vaccinations,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching vaccination logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve vaccination logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch vaccinations for given farm and livestock UUIDs.
     */
    public function fetchVaccinationsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Vaccination::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(function (Vaccination $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'vaccinationNo' => $log->vaccinationNo,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'vaccineId' => $log->vaccineId,
                    'diseaseId' => $log->diseaseId,
                    'vetId' => $log->vetId,
                    'extensionOfficerId' => $log->extensionOfficerId,
                    'status' => $log->status,
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process vaccination records coming from the mobile app.
     */
    public function processVaccinations(array $vaccinations, string $livestockUuid): array
    {
        $syncedVaccinations = [];

        Log::info('========== PROCESSING VACCINATIONS START ==========');
        Log::info('Total vaccinations to process: ' . count($vaccinations));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($vaccinations as $vaccinationData) {
            try {
                $syncAction = $vaccinationData['syncAction'] ?? 'create';
                $uuid = $vaccinationData['uuid'] ?? null;

                if (!$uuid) {
                    Log::warning('⚠️ Vaccination entry without UUID skipped', ['vaccination' => $vaccinationData]);
                    continue;
                }

                Log::info("Processing vaccination: UUID={$uuid}, Action={$syncAction}");

                $vaccinationData['livestockUuid'] = $livestockUuid;
                $farmUuid = $vaccinationData['farmUuid'] ?? null;

                $vaccinationNo = isset($vaccinationData['vaccinationNo'])
                    ? trim((string) $vaccinationData['vaccinationNo'])
                    : null;
                $vaccinationNo = $vaccinationNo === '' ? null : $vaccinationNo;

                $vetId = isset($vaccinationData['vetId'])
                    ? trim((string) $vaccinationData['vetId'])
                    : null;
                $vetId = $vetId === '' ? null : $vetId;

                $extensionOfficerId = isset($vaccinationData['extensionOfficerId'])
                    ? trim((string) $vaccinationData['extensionOfficerId'])
                    : null;
                $extensionOfficerId = $extensionOfficerId === '' ? null : $extensionOfficerId;

                $status = isset($vaccinationData['status'])
                    ? strtolower((string) $vaccinationData['status'])
                    : 'completed';
                if (!in_array($status, ['pending', 'completed', 'failed'], true)) {
                    $status = 'completed';
                }

                $createdAt = isset($vaccinationData['createdAt'])
                    ? Carbon::parse($vaccinationData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($vaccinationData['updatedAt'])
                    ? Carbon::parse($vaccinationData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                switch ($syncAction) {
                    case 'create':
                        $existing = Vaccination::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'vaccinationNo' => $vaccinationNo ?? $existing->vaccinationNo,
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'vaccineId' => $vaccinationData['vaccineId'] ?? null,
                                    'diseaseId' => $vaccinationData['diseaseId'] ?? null,
                                    'vetId' => $vetId,
                                    'extensionOfficerId' => $extensionOfficerId,
                                    'status' => $status,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Vaccination updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Vaccination skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Vaccination::create([
                                'uuid' => $uuid,
                                'vaccinationNo' => $vaccinationNo ?? $uuid,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'vaccineId' => $vaccinationData['vaccineId'] ?? null,
                                'diseaseId' => $vaccinationData['diseaseId'] ?? null,
                                'vetId' => $vetId,
                                'extensionOfficerId' => $extensionOfficerId,
                                'status' => $status,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Vaccination created: UUID {$uuid}");
                        }

                        $syncedVaccinations[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $vaccination = Vaccination::where('uuid', $uuid)->first();

                        if ($vaccination) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($vaccination->updated_at);

                            if ($local->greaterThan($server)) {
                                $vaccination->update([
                                    'vaccinationNo' => $vaccinationNo ?? $vaccination->vaccinationNo,
                                    'farmUuid' => $farmUuid,
                                    'vaccineId' => $vaccinationData['vaccineId'] ?? null,
                                    'diseaseId' => $vaccinationData['diseaseId'] ?? null,
                                    'vetId' => $vetId,
                                    'extensionOfficerId' => $extensionOfficerId,
                                    'status' => $status,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Vaccination updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Vaccination update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Vaccination not found for update: UUID {$uuid}");
                        }

                        $syncedVaccinations[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $vaccination = Vaccination::where('uuid', $uuid)->first();

                        if ($vaccination) {
                            $vaccination->delete();
                            Log::info("✅ Vaccination deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Vaccination already deleted on server: UUID {$uuid}");
                        }

                        $syncedVaccinations[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for vaccination: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING VACCINATION', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $vaccinationData,
                ]);

                continue;
            }
        }

        Log::info('========== PROCESSING VACCINATIONS END ==========');
        Log::info('Total vaccinations synced: ' . count($syncedVaccinations));

        return $syncedVaccinations;
    }
}

