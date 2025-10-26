<?php

namespace App\Http\Controllers\Livestock;

use App\Http\Controllers\Controller;
use App\Models\Livestock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;
use PhpParser\Node\Expr\FuncCall;

class LivestockController extends Controller
{
    /**
     * Display a listing of all livestock.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $livestock = Livestock::with(['farm', 'livestockType', 'breed', 'species', 'livestockObtainedMethod'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Livestock retrieved successfully',
                'data' => $livestock
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all livestock by farm IDs (for a specific farmer).
     * This is used when a farmer logs in to get all their livestock across all their farms.
     *
     * @param array $farmIds
     * @return JsonResponse
     */
    public function getAllLivestockByFarmIds(array $farmIds): JsonResponse
    {
        try {
            $livestock = Livestock::whereIn('farmId', $farmIds)
                ->with(['farm', 'livestockType', 'breed', 'species', 'livestockObtainedMethod', 'mother', 'father'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Livestock retrieved successfully',
                'data' => $livestock,
                'count' => $livestock->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified livestock.
     *
     * @param Livestock $livestock
     * @return JsonResponse
     */
    public function show(Livestock $livestock): JsonResponse
    {
        try {
            $livestock->load(['farm', 'livestockType', 'breed', 'species', 'livestockObtainedMethod', 'mother', 'father']);

            return response()->json([
                'status' => true,
                'message' => 'Livestock retrieved successfully',
                'data' => $livestock
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch livestock by farm UUIDs as array (for sync).
     *
     * @param array $farmUuids
     * @return array
     */
    public function fetchByFarmUuids(array $farmUuids): array
    {
        return Livestock::whereIn('farmUuid', $farmUuids)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($livestock) {
                return [
                    'id' => $livestock->id,
                    'farmUuid' => $livestock->farmUuid,  // Changed from farmId
                    'uuid' => $livestock->uuid,
                    'identificationNumber' => $livestock->identificationNumber,
                    'dummyTagId' => $livestock->dummyTagId,
                    'barcodeTagId' => $livestock->barcodeTagId,
                    'rfidTagId' => $livestock->rfidTagId,
                    'livestockTypeId' => $livestock->livestockTypeId,
                    'name' => $livestock->name,
                    'dateOfBirth' => $livestock->dateOfBirth?->toDateString(),
                    'motherUuid' => $livestock->motherUuid,  // Changed from motherId
                    'fatherUuid' => $livestock->fatherUuid,  // Changed from fatherId
                    'gender' => $livestock->gender,
                    'breedId' => $livestock->breedId,
                    'speciesId' => $livestock->speciesId,
                    'status' => $livestock->status,
                    'livestockObtainedMethodId' => $livestock->livestockObtainedMethodId,
                    'dateFirstEnteredToFarm' => $livestock->dateFirstEnteredToFarm?->toDateString(),
                    'weightAsOnRegistration' => $livestock->weightAsOnRegistration,
                    'createdAt' => $livestock->created_at?->toIso8601String(),
                    'updatedAt' => $livestock->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    public function handlePostLivestockAction(Request $request){
        try {
            // hendelt syncAction delete && update && create || fromTheServecreatedAt-wins-if-is-greate  & fromServerupdatedAt-wins-if-greater
            $data = $request->all();
            Livestock::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Livestock created successfully',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to handle post livestock action',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

