<?php

namespace App\Http\Controllers\Logs\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

trait ProcessesStandardLivestockLog
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  callable(array):array  $extractFields  Model attributes only (no uuid/farm/livestock/eventDate/timestamps)
     * @param  null|callable(string $syncAction, string $livestockUuid, array $logData):void  $afterPersist
     */
    protected function processStandardLivestockLogs(
        array $logs,
        string $livestockUuid,
        string $modelClass,
        string $logLabel,
        callable $extractFields,
        ?callable $afterPersist = null
    ): array {
        $synced = [];

        Log::info("========== PROCESSING {$logLabel} START ==========");
        Log::info('Total logs to process: '.count($logs));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($logs as $logData) {
            try {
                $syncAction = $logData['syncAction'] ?? 'create';
                $uuid = $logData['uuid'] ?? null;

                if (! $uuid) {
                    Log::warning('⚠️ Skipped log without UUID', ['label' => $logLabel]);

                    continue;
                }

                Log::info("Processing {$logLabel}: UUID={$uuid}, Action={$syncAction}");

                $logData['livestockUuid'] = $livestockUuid;
                $farmUuid = $logData['farmUuid'] ?? null;

                $createdAt = isset($logData['createdAt'])
                    ? Carbon::parse($logData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($logData['updatedAt'])
                    ? Carbon::parse($logData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                $eventDate = isset($logData['eventDate'])
                    ? Carbon::parse($logData['eventDate'])->format('Y-m-d H:i:s')
                    : $createdAt;

                $attributes = $extractFields($logData);

                switch ($syncAction) {
                    case 'create':
                        $existing = $modelClass::where('uuid', $uuid)->first();

                        if ($existing) {
                            if (Carbon::parse($updatedAt)->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update(array_merge($attributes, [
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]));
                                Log::info("✅ {$logLabel} updated (local newer): UUID {$uuid}");
                                if ($afterPersist) {
                                    $afterPersist('create', $livestockUuid, $logData);
                                }
                            } else {
                                Log::info('⏭️ Skip update, server newer');
                            }
                        } else {
                            $modelClass::create(array_merge($attributes, [
                                'uuid' => $uuid,
                                'eventDate' => $eventDate,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]));
                            Log::info("✅ {$logLabel} created: UUID {$uuid}");
                            if ($afterPersist) {
                                $afterPersist('create', $livestockUuid, $logData);
                            }
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $log = $modelClass::where('uuid', $uuid)->first();

                        if ($log) {
                            if (Carbon::parse($updatedAt)->greaterThan(Carbon::parse($log->updated_at))) {
                                $log->update(array_merge($attributes, [
                                    'farmUuid' => $farmUuid,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]));
                                Log::info("✅ {$logLabel} updated: UUID {$uuid}");
                                if ($afterPersist) {
                                    $afterPersist('update', $livestockUuid, $logData);
                                }
                            } else {
                                Log::info('⏭️ Skip update, server newer');
                            }
                        } else {
                            Log::warning("⚠️ {$logLabel} UUID not found for update");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $log = $modelClass::where('uuid', $uuid)->first();

                        if ($log) {
                            $log->delete();
                            Log::info("✅ {$logLabel} deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Already deleted on server: UUID {$uuid}");
                        }

                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for {$logLabel}: {$syncAction}");
                        break;
                }
            } catch (\Exception $e) {
                Log::error("❌ ERROR PROCESSING {$logLabel}", [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $logData,
                ]);

                continue;
            }
        }

        Log::info("========== PROCESSING {$logLabel} END ==========");
        Log::info('Total logs synced: '.count($synced));

        return $synced;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function fetchStandardLogsWithUuid($farmUuids, $livestockUuids, string $modelClass, callable $serialize): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return $modelClass::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(fn ($log) => $serialize($log))
            ->toArray();
    }
}
