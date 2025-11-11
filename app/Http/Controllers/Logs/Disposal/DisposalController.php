<?php

namespace App\Http\Controllers\Logs\Disposal;

use App\Http\Controllers\Controller;
use App\Models\Disposal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DisposalController extends Controller
{
    /**
     * Display a listing of disposals.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $disposals = Disposal::with(['livestock', 'farm', 'disposalType'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Disposal logs retrieved successfully',
                'data' => $disposals,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching disposals: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve disposals',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch disposals for given farm and livestock UUIDs.
     */
    public function fetchDisposalsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Disposal::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(function (Disposal $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'disposalTypeId' => $log->disposalTypeId,
                    'reasons' => $log->reasons,
                    'remarks' => $log->remarks,
                    'status' => $log->status,
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process disposal records coming from the mobile app.
     */
    public function processDisposals(array $disposals, string $livestockUuid): array
    {
        $syncedDisposals = [];

        Log::info('========== PROCESSING DISPOSALS START ==========');
        Log::info('Total disposals to process: ' . count($disposals));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($disposals as $disposalData) {
            try {
                $syncAction = $disposalData['syncAction'] ?? 'create';
                $uuid = $disposalData['uuid'] ?? null;

                if (!$uuid) {
                    Log::warning('⚠️ Disposal entry without UUID skipped', ['disposal' => $disposalData]);
                    continue;
                }

                Log::info("Processing disposal: UUID={$uuid}, Action={$syncAction}");

                $disposalData['livestockUuid'] = $livestockUuid;
                $farmUuid = $disposalData['farmUuid'] ?? null;

                $status = isset($disposalData['status'])
                    ? strtolower((string) $disposalData['status'])
                    : 'completed';
                if (!in_array($status, ['pending', 'completed', 'failed'], true)) {
                    $status = 'completed';
                }

                $createdAt = isset($disposalData['createdAt'])
                    ? Carbon::parse($disposalData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($disposalData['updatedAt'])
                    ? Carbon::parse($disposalData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                switch ($syncAction) {
                    case 'create':
                        $existing = Disposal::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'disposalTypeId' => $disposalData['disposalTypeId'] ?? null,
                                    'reasons' => $disposalData['reasons'] ?? $existing->reasons,
                                    'remarks' => $disposalData['remarks'] ?? $existing->remarks,
                                    'status' => $status,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Disposal updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Disposal skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Disposal::create([
                                'uuid' => $uuid,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'disposalTypeId' => $disposalData['disposalTypeId'] ?? null,
                                'reasons' => $disposalData['reasons'] ?? '',
                                'remarks' => $disposalData['remarks'] ?? '',
                                'status' => $status,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Disposal created: UUID {$uuid}");
                        }

                        $syncedDisposals[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $disposal = Disposal::where('uuid', $uuid)->first();

                        if ($disposal) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($disposal->updated_at);

                            if ($local->greaterThan($server)) {
                                $disposal->update([
                                    'farmUuid' => $farmUuid,
                                    'disposalTypeId' => $disposalData['disposalTypeId'] ?? null,
                                    'reasons' => $disposalData['reasons'] ?? $disposal->reasons,
                                    'remarks' => $disposalData['remarks'] ?? $disposal->remarks,
                                    'status' => $status,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Disposal updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Disposal update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Disposal not found for update: UUID {$uuid}");
                        }

                        $syncedDisposals[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $disposal = Disposal::where('uuid', $uuid)->first();

                        if ($disposal) {
                            $disposal->delete();
                            Log::info("✅ Disposal deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Disposal already deleted on server: UUID {$uuid}");
                        }

                        $syncedDisposals[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for disposal: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING DISPOSAL', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $disposalData,
                ]);

                continue;
            }
        }

        Log::info('========== PROCESSING DISPOSALS END ==========');
        Log::info('Total disposals synced: ' . count($syncedDisposals));

        return $syncedDisposals;
    }
}

