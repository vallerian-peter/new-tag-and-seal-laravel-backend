<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CooperativeSeasonController extends Controller
{
    /**
     * POST /api/v2/dairy/seasons
     * MCU / AMCOS sets seasonal price per unit (e.g. per L or per kg).
     */
    public function saveSeason(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => 'nullable|string|max:64',
            'mcu_uuid' => 'nullable|string|exists:cooperative_mcus,uuid',
            'amcos_uuid' => 'nullable|string|exists:cooperative_amcos,uuid',
            'unit' => 'nullable|string|exists:measurement_units,code',
            'name' => 'required|string|max:255',
            'price_per_unit' => 'required|numeric|min:1',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        if (empty($data['amcos_uuid'])) {
            CooperativeAccess::deny('Select a cooperative to set its season price.');
        }
        CooperativeAccess::requireStaff($request->user()->id, $data['amcos_uuid'], true);
        $existingSeason = ! empty($data['uuid'])
            ? DB::table('cooperative_seasons')->where('uuid', $data['uuid'])->first()
            : null;
        if ($existingSeason && $existingSeason->amcos_uuid !== $data['amcos_uuid']) {
            CooperativeAccess::deny('This season belongs to another cooperative.');
        }

        $amcosUuid = $data['amcos_uuid'] ?? null;
        $mcuUuid = $data['mcu_uuid'] ?? null;
        $unit = $data['unit'] ?? 'l';
        if ($amcosUuid && ! $mcuUuid) {
            $amcosRow = DB::table('cooperative_amcos')->where('uuid', $amcosUuid)->first();
            $mcuUuid = $amcosRow?->mcu_uuid;
            $unit = $data['unit'] ?? ($amcosRow?->default_unit ?? 'l');
        }
        if (! $mcuUuid && ! $amcosUuid) {
            $mcuUuid = DB::table('cooperative_mcus')->value('uuid');
        }

        $uuid = $data['uuid'] ?? (string) Str::uuid();
        $isActive = $data['is_active'] ?? true;

        return DB::transaction(function () use ($request, $data, $uuid, $mcuUuid, $amcosUuid, $unit, $isActive, $existingSeason) {
            if ($isActive) {
                $deactivateQuery = DB::table('cooperative_seasons')
                    ->where('uuid', '!=', $uuid)
                    ->where('unit', $unit);
                if ($amcosUuid) {
                    $deactivateQuery->where('amcos_uuid', $amcosUuid);
                } elseif ($mcuUuid) {
                    $deactivateQuery->where('mcu_uuid', $mcuUuid)->whereNull('amcos_uuid');
                }
                $deactivateQuery->update(['is_active' => false, 'updated_at' => now()]);
            }

            DB::table('cooperative_seasons')->updateOrInsert(
                ['uuid' => $uuid],
                [
                    'season_code' => $existingSeason->season_code ?? ('SEASON-' . strtoupper(Str::random(5))),
                    'unit' => $unit,
                    'mcu_uuid' => $mcuUuid,
                    'amcos_uuid' => $amcosUuid,
                    'name' => trim($data['name']),
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'] ?? null,
                    'price_per_unit' => round((float) $data['price_per_unit'], 2),
                    'is_active' => $isActive,
                    'created_by_user_id' => $existingSeason->created_by_user_id ?? $request->user()->id,
                    'updated_at' => now(),
                    'created_at' => $existingSeason->created_at ?? now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'Season and price per unit saved successfully.',
                'data' => DB::table('cooperative_seasons')->where('uuid', $uuid)->first(),
            ], 201);
        });
    }
}
