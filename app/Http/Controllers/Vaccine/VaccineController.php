<?php

namespace App\Http\Controllers\Vaccine;

use App\Http\Controllers\Controller;
use App\Models\Vaccine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VaccineController extends Controller
{
    /**
     * Fetch all vaccines associated with the provided farm UUIDs.
     *
     * @param array $farmUuids
     * @return array<int, array<string, mixed>>
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

        return $vaccines->map(static function (Vaccine $vaccine): array {
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
     * Process vaccine records provided by the mobile application.
     *
     * @param array<int, array<string, mixed>> $vaccines
     * @param array<int, string> $allowedFarmUuids
     * @return array<int, array<string, string>>
     */
    public function processVaccines(array $vaccines, array $allowedFarmUuids): array
    {
        $synced = [];

        Log::info('========== PROCESSING VACCINES START ==========');
        Log::info('Total vaccines to process: ' . count($vaccines));

        foreach ($vaccines as $vaccineData) {
            $uuid = $vaccineData['uuid'] ?? null;
            $syncAction = $vaccineData['syncAction'] ?? 'create';
            $farmUuid = $vaccineData['farmUuid'] ?? null;

            try {
                if (!$uuid) {
                    Log::warning('⚠️ Vaccine entry without UUID skipped', ['payload' => $vaccineData]);
                    continue;
                }

                if (!empty($allowedFarmUuids) && $farmUuid && !in_array($farmUuid, $allowedFarmUuids, true)) {
                    Log::warning("⚠️ Vaccine farm UUID not permitted: {$farmUuid}", ['uuid' => $uuid]);
                    continue;
                }

                $createdAt = isset($vaccineData['createdAt'])
                    ? Carbon::parse($vaccineData['createdAt'])->format('Y-m-d H:i:s')
                    : now();
                $updatedAt = isset($vaccineData['updatedAt'])
                    ? Carbon::parse($vaccineData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                $name = isset($vaccineData['name'])
                    ? trim((string) $vaccineData['name'])
                    : '';

                $lot = isset($vaccineData['lot'])
                    ? trim((string) $vaccineData['lot'])
                    : null;
                $lot = $lot === '' ? null : $lot;

                $formulationType = isset($vaccineData['formulationType'])
                    ? trim((string) $vaccineData['formulationType'])
                    : null;
                $formulationType = $formulationType === '' ? null : $formulationType;

                $dose = isset($vaccineData['dose'])
                    ? trim((string) $vaccineData['dose'])
                    : null;
                $dose = $dose === '' ? null : $dose;

                $status = isset($vaccineData['status'])
                    ? strtolower((string) $vaccineData['status'])
                    : 'active';
                if (!in_array($status, ['active', 'inactive', 'expired'], true)) {
                    $status = 'active';
                }

                $vaccineTypeId = $vaccineData['vaccineTypeId'] ?? null;
                if ($vaccineTypeId !== null && !is_numeric($vaccineTypeId)) {
                    $vaccineTypeId = null;
                }
                $vaccineTypeId = $vaccineTypeId !== null ? (int) $vaccineTypeId : null;

                $vaccineSchedule = isset($vaccineData['vaccineSchedule'])
                    ? trim((string) $vaccineData['vaccineSchedule'])
                    : null;
                $vaccineSchedule = $vaccineSchedule === '' ? null : $vaccineSchedule;

                switch ($syncAction) {
                    case 'create':
                        $existing = Vaccine::where('uuid', $uuid)->first();

                        if ($existing) {
                            $localUpdatedAt = Carbon::parse($updatedAt);
                            $serverUpdatedAt = Carbon::parse($existing->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'name' => $name,
                                    'lot' => $lot,
                                    'formulationType' => $formulationType,
                                    'dose' => $dose,
                                    'status' => $status,
                                    'vaccineTypeId' => $vaccineTypeId,
                                    'vaccineSchedule' => $vaccineSchedule,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Vaccine updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Vaccine skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Vaccine::create([
                                'uuid' => $uuid,
                                'farmUuid' => $farmUuid,
                                'name' => $name,
                                'lot' => $lot,
                                'formulationType' => $formulationType,
                                'dose' => $dose,
                                'status' => $status,
                                'vaccineTypeId' => $vaccineTypeId,
                                'vaccineSchedule' => $vaccineSchedule,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);
                            Log::info("✅ Vaccine created: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $existing = Vaccine::where('uuid', $uuid)->first();

                        if ($existing) {
                            $localUpdatedAt = Carbon::parse($updatedAt);
                            $serverUpdatedAt = Carbon::parse($existing->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'name' => $name ?: $existing->name,
                                    'lot' => $lot,
                                    'formulationType' => $formulationType,
                                    'dose' => $dose,
                                    'status' => $status,
                                    'vaccineTypeId' => $vaccineTypeId,
                                    'vaccineSchedule' => $vaccineSchedule,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Vaccine updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Vaccine update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Vaccine not found for update: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $existing = Vaccine::where('uuid', $uuid)->first();

                        if ($existing) {
                            $existing->delete();
                            Log::info("✅ Vaccine deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Vaccine already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for vaccine: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING VACCINE', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $vaccineData,
                ]);
                continue;
            }
        }

        Log::info('========== PROCESSING VACCINES END ==========');
        Log::info('Total vaccines synced: ' . count($synced));

        return $synced;
    }
}

