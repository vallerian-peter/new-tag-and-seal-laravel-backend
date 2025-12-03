<?php

namespace App\Http\Controllers\Vaccine;

use App\Http\Controllers\Controller;
use App\Models\Vaccine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VaccineController extends Controller
{
    /**
     * Fetch all vaccines associated with the provided farm UUIDs.
     *
     * @param array $farmUuids
     * @return array
     */
    public function fetchByFarmUuids(array $farmUuids): array
    {
        if (empty($farmUuids)) {
            return [];
        }

        /** @var Collection<int, Vaccine> $vaccines */
        $vaccines = Vaccine::whereIn('farmUuid', $farmUuids)
            ->orderBy('created_at', 'desc')
            ->get();

        return $vaccines->map(function (Vaccine $vaccine) {
            return [
                'id' => $vaccine->id,
                'uuid' => $vaccine->uuid,
                'farmUuid' => $vaccine->farmUuid,
                'name' => $vaccine->name,
                'lot' => $vaccine->lot,
                'formulationType' => $vaccine->formulationType,
                'dose' => $vaccine->dose,
                'status' => $vaccine->status,
                'vaccineTypeId' => $vaccine->vaccineTypeId,
                'vaccineSchedule' => $vaccine->vaccineSchedule,
                'createdAt' => $vaccine->created_at?->toIso8601String(),
                'updatedAt' => $vaccine->updated_at?->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Process vaccines during sync (create/update/delete).
     * Similar to processLivestock method pattern.
     *
     * @param array $vaccines
     * @param int $farmerId
     * @return array
     */
    public function processVaccines(array $vaccines, int $farmerId): array
    {
        $syncedVaccines = [];

        Log::info("========== PROCESSING VACCINES START ==========");
        Log::info("Total vaccines to process: " . count($vaccines));
        Log::info("Authenticated Farmer ID: {$farmerId}");

        foreach ($vaccines as $vaccineData) {
            try {
                $syncAction = $vaccineData['syncAction'] ?? 'create';
                $uuid = $vaccineData['uuid'] ?? null;

                Log::info("Processing vaccine: UUID={$uuid}, Action={$syncAction}, Name={$vaccineData['name']}");

                if (!$uuid) {
                    Log::warning('⚠️ Vaccine without UUID skipped', ['vaccine' => $vaccineData]);
                    continue;
                }

                // Convert data types from Flutter to MySQL format
                $createdAt = isset($vaccineData['createdAt'])
                    ? \Carbon\Carbon::parse($vaccineData['createdAt'])->format('Y-m-d H:i:s')
                    : now();
                $updatedAt = isset($vaccineData['updatedAt'])
                    ? \Carbon\Carbon::parse($vaccineData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                Log::info("Converted data types - Created: {$createdAt}, Updated: {$updatedAt}");

                switch ($syncAction) {
                    case 'create':
                        // Create new vaccine or update if exists (upsert)
                        $existingVaccine = Vaccine::where('uuid', $uuid)->first();

                        if ($existingVaccine) {
                            // Vaccine exists - check if local is newer
                            $localUpdatedAt = \Carbon\Carbon::parse($updatedAt);
                            $serverUpdatedAt = \Carbon\Carbon::parse($existingVaccine->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                // Local is newer - update
                                $existingVaccine->update([
                                    'farmUuid' => $vaccineData['farmUuid'],
                                    'name' => $vaccineData['name'],
                                    'lot' => $vaccineData['lot'] ?? null,
                                    'formulationType' => $vaccineData['formulationType'] ?? null,
                                    'dose' => $vaccineData['dose'] ?? null,
                                    'status' => $vaccineData['status'] ?? 'active',
                                    'vaccineTypeId' => $vaccineData['vaccineTypeId'] ?? null,
                                    'vaccineSchedule' => $vaccineData['vaccineSchedule'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Vaccine updated (local newer): {$existingVaccine->name} (UUID: {$uuid})");
                                $syncedVaccines[] = $existingVaccine;
                            } else {
                                Log::info("⏭️ Vaccine skipped (server newer): {$existingVaccine->name} (UUID: {$uuid})");
                                $syncedVaccines[] = $existingVaccine;
                            }
                        } else {
                            // Vaccine doesn't exist - create new
                            Log::info("Creating new vaccine: {$vaccineData['name']} (farmUuid: {$vaccineData['farmUuid']})");

                            $newVaccine = Vaccine::create([
                                'uuid' => $uuid,
                                'farmUuid' => $vaccineData['farmUuid'],
                                'name' => $vaccineData['name'],
                                'lot' => $vaccineData['lot'] ?? null,
                                'formulationType' => $vaccineData['formulationType'] ?? null,
                                'dose' => $vaccineData['dose'] ?? null,
                                'status' => $vaccineData['status'] ?? 'active',
                                'vaccineTypeId' => $vaccineData['vaccineTypeId'] ?? null,
                                'vaccineSchedule' => $vaccineData['vaccineSchedule'] ?? null,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);
                            Log::info("✅ Vaccine created: {$newVaccine->name} (UUID: {$uuid})");
                            $syncedVaccines[] = $newVaccine;
                        }
                        break;

                    case 'server-update':
                        // Server update - just acknowledge (already synced from server)
                        $existingVaccine = Vaccine::where('uuid', $uuid)->first();
                        if ($existingVaccine) {
                            $syncedVaccines[] = $existingVaccine;
                            Log::info("✅ Vaccine server-update acknowledged: {$existingVaccine->name} (UUID: {$uuid})");
                        }
                        break;

                    default:
                        Log::warning("Unknown sync action for vaccine: {$syncAction}", ['vaccine' => $vaccineData]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error("❌ Failed to process vaccine: " . $e->getMessage(), [
                    'vaccine' => $vaccineData,
                    'trace' => $e->getTraceAsString(),
                ]);
                continue;
            }
        }

        Log::info("========== PROCESSING VACCINES END ==========");
        Log::info("Total vaccines synced: " . count($syncedVaccines));

        return $syncedVaccines;
    }
}

