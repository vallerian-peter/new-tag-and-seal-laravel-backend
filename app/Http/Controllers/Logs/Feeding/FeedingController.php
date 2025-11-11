<?php

namespace App\Http\Controllers\Logs\Feeding;

use Carbon\Carbon;
use App\Models\Feeding;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class FeedingController extends Controller
{
    /**
     * Display a listing of feedings with optional search and pagination.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $feedings = Feeding::with(['livestock', 'feedingType', 'farm'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Feedings retrieved successfully',
                'data' => $feedings
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching feedings: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve feedings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function fetchFeedingsWithUuid($farmUuid, $livestockUuid){
        return Feeding::whereIn('farmUuid', $farmUuid)
            ->whereIn('livestockUuid', $livestockUuid)
            ->get()
            ->map(function ($feed) {
                return [
                    'id'=>$feed->id,
                    'uuid' => $feed->uuid,
                    'farmUuid' => $feed->farmUuid,
                    'livestockUuid' => $feed->livestockUuid,
                    'feedingTypeId' => $feed->feedingTypeId,
                    'nextFeedingTime' => $feed->nextFeedingTime,
                    'amount' => $feed->amount,
                    'remarks' => $feed->remarks,
                    'createdAt' => $feed->created_at?->toIso8601String(),
                    'updatedAt' => $feed->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process multiple feeding records from mobile app
     * Handles create, update, and delete operations
     *
     * @param array $feedings Array of feeding data from mobile app
     * @param int $livestockId Livestock ID to associate with feedings
     * @return array Array of synced feeding UUIDs
     */
    public function processFeedings(array $feedings, string $livestockUuid): array
    {
        $syncedFeedings = [];

        Log::info("========== PROCESSING FEEDINGS START ==========");
        Log::info("Total feedings to process: " . count($feedings));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($feedings as $feedingData) {
            try {
                $syncAction = $feedingData['syncAction'] ?? 'create';
                $uuid = $feedingData['uuid'] ?? null;

                Log::info("Processing feeding: UUID={$uuid}, Action={$syncAction}");

                if (!$uuid) {
                    Log::warning('⚠️ Feeding without UUID skipped', ['feeding' => $feedingData]);
                    continue;
                }

                // force correct livestock uuid
                $feedingData['livestockUuid'] = $livestockUuid;

                // Ensure feeding has farm UUID (required by DB)
                $farmUuid = $feedingData['farmUuid'] ?? null;

                // Convert timestamps / data from mobile
                $nextFeedingTime = isset($feedingData['nextFeedingTime'])
                    ? Carbon::parse($feedingData['nextFeedingTime'])->format('Y-m-d H:i:s')
                    : now()->format('Y-m-d H:i:s');

                $createdAt = isset($feedingData['createdAt'])
                    ? Carbon::parse($feedingData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($feedingData['updatedAt'])
                    ? Carbon::parse($feedingData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Amount is stored as string to retain unit (e.g. "54kg")
                $amount = isset($feedingData['amount'])
                    ? trim((string) $feedingData['amount'])
                    : '';

                Log::info("Converted data - Amount: {$amount}, NextFeedingTime: {$nextFeedingTime}");

                switch ($syncAction) {

                    case 'create':
                        $existing = Feeding::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'feedingTypeId' => $feedingData['feedingTypeId'],
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'nextFeedingTime' => $nextFeedingTime,
                                    'amount' => $amount,
                                    'remarks' => $feedingData['remarks'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Feeding updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Feeding skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Feeding::create([
                                'uuid' => $uuid,
                                'feedingTypeId' => $feedingData['feedingTypeId'],
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'nextFeedingTime' => $nextFeedingTime,
                                'amount' => $amount,
                                'remarks' => $feedingData['remarks'] ?? null,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Feeding created: UUID {$uuid}");
                        }

                        $syncedFeedings[] = ['uuid' => $uuid];
                        break;


                    case 'update':
                        $feeding = Feeding::where('uuid', $uuid)->first();

                        if ($feeding) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($feeding->updated_at);

                            if ($local->greaterThan($server)) {
                                $feeding->update([
                                    'feedingTypeId' => $feedingData['feedingTypeId'],
                                    'farmUuid' => $farmUuid,
                                    'nextFeedingTime' => $nextFeedingTime,
                                    'amount' => $amount,
                                    'remarks' => $feedingData['remarks'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Feeding updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Feeding update skipped: UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Feeding not found for update: UUID {$uuid}");
                        }

                        $syncedFeedings[] = ['uuid' => $uuid];
                        break;


                    case 'deleted':
                        $feeding = Feeding::where('uuid', $uuid)->first();

                        if ($feeding) {
                            $feeding->delete();
                            Log::info("✅ Feeding deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Feeding already deleted on server: UUID {$uuid}");
                        }

                        $syncedFeedings[] = ['uuid' => $uuid];
                        break;


                    default:
                        Log::warning("⚠️ Unknown sync action for feeding: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error("❌ ERROR PROCESSING FEEDING", [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'feedingData' => $feedingData,
                ]);

                continue;
            }
        }

        Log::info("========== PROCESSING FEEDINGS END ==========");
        Log::info("Total feedings synced: " . count($syncedFeedings));
        return $syncedFeedings;
    }
}
