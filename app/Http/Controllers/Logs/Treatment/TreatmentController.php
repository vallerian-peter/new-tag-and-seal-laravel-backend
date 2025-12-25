<?php

namespace App\Http\Controllers\Logs\Treatment;

use App\Http\Controllers\Controller;
use App\Models\Treatment;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TreatmentController extends Controller
{
    use ConvertsDateFormat;
    /**
     * Display a listing of treatments.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $treatments = Treatment::with(['livestock', 'farm', 'disease', 'medicine'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Treatment logs retrieved successfully',
                'data' => $treatments,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching treatment logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve treatment logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch treatments for given farm and livestock UUIDs.
     */
    public function fetchTreatmentsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Treatment::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(function (Treatment $log) {
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
                    'nextMedicationDate' => $log->nextMedicationDate,
                    'remarks' => $log->remarks,
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process treatment records coming from the mobile app.
     */
    public function processTreatments(array $treatments, string $livestockUuid): array
    {
        $syncedTreatments = [];

        Log::info('========== PROCESSING TREATMENTS START ==========');
        Log::info('Total treatments to process: ' . count($treatments));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($treatments as $treatmentData) {
            try {
                $syncAction = $treatmentData['syncAction'] ?? 'create';
                $uuid = $treatmentData['uuid'] ?? null;

                if (!$uuid) {
                    Log::warning('⚠️ Treatment entry without UUID skipped', ['treatment' => $treatmentData]);
                    continue;
                }

                Log::info("Processing treatment: UUID={$uuid}, Action={$syncAction}");

                $treatmentData['livestockUuid'] = $livestockUuid;
                $farmUuid = $treatmentData['farmUuid'] ?? null;

                $quantity = isset($treatmentData['quantity'])
                    ? trim((string) $treatmentData['quantity'])
                    : null;
                $quantity = $quantity === '' ? null : $quantity;

                $withdrawalPeriod = isset($treatmentData['withdrawalPeriod'])
                    ? trim((string) $treatmentData['withdrawalPeriod'])
                    : null;
                $withdrawalPeriod = $withdrawalPeriod === '' ? null : $withdrawalPeriod;

                $medicationDate = $this->convertDateFormat($treatmentData['medicationDate'] ?? null);
                $nextMedicationDate = $this->convertDateFormat($treatmentData['nextMedicationDate'] ?? null);

                $createdAt = isset($treatmentData['createdAt'])
                    ? Carbon::parse($treatmentData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($treatmentData['updatedAt'])
                    ? Carbon::parse($treatmentData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                switch ($syncAction) {
                    case 'create':
                        $existing = Treatment::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'diseaseId' => $treatmentData['diseaseId'] ?? null,
                                    'medicineId' => $treatmentData['medicineId'] ?? null,
                                    'quantity' => $quantity,
                                    'withdrawalPeriod' => $withdrawalPeriod,
                                    'medicationDate' => $medicationDate,
                                    'nextMedicationDate' => $nextMedicationDate,
                                    'remarks' => $treatmentData['remarks'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Treatment updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Treatment skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Treatment::create([
                                'uuid' => $uuid,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'diseaseId' => $treatmentData['diseaseId'] ?? null,
                                'medicineId' => $treatmentData['medicineId'] ?? null,
                                'quantity' => $quantity,
                                'withdrawalPeriod' => $withdrawalPeriod,
                                'medicationDate' => $medicationDate,
                                'nextMedicationDate' => $nextMedicationDate,
                                'remarks' => $treatmentData['remarks'] ?? null,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Treatment created: UUID {$uuid}");
                        }

                        $syncedTreatments[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $treatment = Treatment::where('uuid', $uuid)->first();

                        if ($treatment) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($treatment->updated_at);

                            if ($local->greaterThan($server)) {
                                $treatment->update([
                                    'farmUuid' => $farmUuid,
                                    'diseaseId' => $treatmentData['diseaseId'] ?? null,
                                    'medicineId' => $treatmentData['medicineId'] ?? null,
                                    'quantity' => $quantity,
                                    'withdrawalPeriod' => $withdrawalPeriod,
                                    'medicationDate' => $medicationDate,
                                    'nextMedicationDate' => $nextMedicationDate,
                                    'remarks' => $treatmentData['remarks'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Treatment updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Treatment update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Treatment not found for update: UUID {$uuid}");
                        }

                        $syncedTreatments[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $treatment = Treatment::where('uuid', $uuid)->first();

                        if ($treatment) {
                            $treatment->delete();
                            Log::info("✅ Treatment deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Treatment already deleted on server: UUID {$uuid}");
                        }

                        $syncedTreatments[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for treatment: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING TREATMENT', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $treatmentData,
                ]);

                continue;
            }
        }

        Log::info('========== PROCESSING TREATMENTS END ==========');
        Log::info('Total treatments synced: ' . count($syncedTreatments));

        return $syncedTreatments;
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $treatments = Treatment::with(['livestock', 'farm', 'disease', 'medicine'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Treatments retrieved successfully',
            'data' => $treatments,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:treatments,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestock,uuid',
            'diseaseId' => 'nullable|integer|exists:diseases,id',
            'medicineId' => 'nullable|integer|exists:medicines,id',
            'quantity' => 'nullable|string|max:255',
            'withdrawalPeriod' => 'nullable|string|max:255',
            'medicationDate' => 'nullable|date',
            'nextMedicationDate' => 'nullable|date',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        if ($request->has('medicationDate')) {
            $data['medicationDate'] = $this->convertDateFormat($request->medicationDate);
        }
        if ($request->has('nextMedicationDate')) {
            $data['nextMedicationDate'] = $this->convertDateFormat($request->nextMedicationDate);
        }

        $treatment = Treatment::create($data);

        $treatment->load(['livestock', 'farm', 'disease', 'medicine']);

        return response()->json([
            'status' => true,
            'message' => 'Treatment created successfully',
            'data' => $treatment,
        ], 201);
    }

    public function adminShow(Treatment $treatment): JsonResponse
    {
        $treatment->load(['livestock', 'farm', 'disease', 'medicine']);

        return response()->json([
            'status' => true,
            'message' => 'Treatment retrieved successfully',
            'data' => $treatment,
        ], 200);
    }

    public function adminUpdate(Request $request, Treatment $treatment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:treatments,uuid,' . $treatment->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestock,uuid',
            'diseaseId' => 'sometimes|nullable|integer|exists:diseases,id',
            'medicineId' => 'sometimes|nullable|integer|exists:medicines,id',
            'quantity' => 'sometimes|nullable|string|max:255',
            'withdrawalPeriod' => 'sometimes|nullable|string|max:255',
            'medicationDate' => 'sometimes|nullable|date',
            'nextMedicationDate' => 'sometimes|nullable|date',
            'remarks' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['medicationDate', 'nextMedicationDate']);
        
        if ($request->has('medicationDate')) {
            $data['medicationDate'] = $this->convertDateFormat($request->medicationDate);
        }
        if ($request->has('nextMedicationDate')) {
            $data['nextMedicationDate'] = $this->convertDateFormat($request->nextMedicationDate);
        }

        $treatment->fill($data);
        $treatment->save();

        $treatment->load(['livestock', 'farm', 'disease', 'medicine']);

        return response()->json([
            'status' => true,
            'message' => 'Treatment updated successfully',
            'data' => $treatment,
        ], 200);
    }

    public function adminDestroy(Treatment $treatment): JsonResponse
    {
        $treatment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Treatment deleted successfully',
        ], 200);
    }
}

