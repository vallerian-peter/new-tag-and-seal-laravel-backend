<?php

namespace App\Http\Controllers\Logs\Medication;

use App\Http\Controllers\Controller;
use App\Models\Medication;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MedicationController extends Controller
{
    /**
     * Display a listing of medications.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $medications = Medication::with(['livestock', 'farm', 'disease', 'medicine'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Medication logs retrieved successfully',
                'data' => $medications,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching medication logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve medication logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch medications for given farm and livestock UUIDs.
     */
    public function fetchMedicationsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Medication::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(function (Medication $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'diseaseId' => $log->diseaseId,
                    'medicineId' => $log->medicineId,
                    'quantity' => $log->quantity,
                    'withdrawalPeriod' => $log->withdrawalPeriod,
                    'medicationDate' => $log->medicationDate,
                    'remarks' => $log->remarks,
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process medication records coming from the mobile app.
     */
    public function processMedications(array $medications, string $livestockUuid): array
    {
        $syncedMedications = [];

        Log::info('========== PROCESSING MEDICATIONS START ==========');
        Log::info('Total medications to process: ' . count($medications));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($medications as $medicationData) {
            try {
                $syncAction = $medicationData['syncAction'] ?? 'create';
                $uuid = $medicationData['uuid'] ?? null;

                if (!$uuid) {
                    Log::warning('⚠️ Medication entry without UUID skipped', ['medication' => $medicationData]);
                    continue;
                }

                Log::info("Processing medication: UUID={$uuid}, Action={$syncAction}");

                $medicationData['livestockUuid'] = $livestockUuid;
                $farmUuid = $medicationData['farmUuid'] ?? null;

                $quantity = isset($medicationData['quantity'])
                    ? trim((string) $medicationData['quantity'])
                    : null;
                $quantity = $quantity === '' ? null : $quantity;

                $withdrawalPeriod = isset($medicationData['withdrawalPeriod'])
                    ? trim((string) $medicationData['withdrawalPeriod'])
                    : null;
                $withdrawalPeriod = $withdrawalPeriod === '' ? null : $withdrawalPeriod;

                $medicationDate = isset($medicationData['medicationDate'])
                    ? Carbon::parse($medicationData['medicationDate'])->format('Y-m-d')
                    : null;

                $createdAt = isset($medicationData['createdAt'])
                    ? Carbon::parse($medicationData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($medicationData['updatedAt'])
                    ? Carbon::parse($medicationData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                switch ($syncAction) {
                    case 'create':
                        $existing = Medication::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'diseaseId' => $medicationData['diseaseId'] ?? null,
                                    'medicineId' => $medicationData['medicineId'] ?? null,
                                    'quantity' => $quantity,
                                    'withdrawalPeriod' => $withdrawalPeriod,
                                    'medicationDate' => $medicationDate,
                                    'remarks' => $medicationData['remarks'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Medication updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Medication skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Medication::create([
                                'uuid' => $uuid,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'diseaseId' => $medicationData['diseaseId'] ?? null,
                                'medicineId' => $medicationData['medicineId'] ?? null,
                                'quantity' => $quantity,
                                'withdrawalPeriod' => $withdrawalPeriod,
                                'medicationDate' => $medicationDate,
                                'remarks' => $medicationData['remarks'] ?? null,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Medication created: UUID {$uuid}");
                        }

                        $syncedMedications[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $medication = Medication::where('uuid', $uuid)->first();

                        if ($medication) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($medication->updated_at);

                            if ($local->greaterThan($server)) {
                                $medication->update([
                                    'farmUuid' => $farmUuid,
                                    'diseaseId' => $medicationData['diseaseId'] ?? null,
                                    'medicineId' => $medicationData['medicineId'] ?? null,
                                    'quantity' => $quantity,
                                    'withdrawalPeriod' => $withdrawalPeriod,
                                    'medicationDate' => $medicationDate,
                                    'remarks' => $medicationData['remarks'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Medication updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Medication update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Medication not found for update: UUID {$uuid}");
                        }

                        $syncedMedications[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $medication = Medication::where('uuid', $uuid)->first();

                        if ($medication) {
                            $medication->delete();
                            Log::info("✅ Medication deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Medication already deleted on server: UUID {$uuid}");
                        }

                        $syncedMedications[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for medication: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING MEDICATION', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $medicationData,
                ]);

                continue;
            }
        }

        Log::info('========== PROCESSING MEDICATIONS END ==========');
        Log::info('Total medications synced: ' . count($syncedMedications));

        return $syncedMedications;
    }
}

