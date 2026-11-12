<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;
use App\Services\FarmerProfileFields;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionCentreController extends Controller
{
    /**
     * POST /api/v2/dairy/centres
     * Upserts a Collection Centre (CC) under MCU -> AMCOS by its client UUID,
     * so a retried offline upload updates the centre instead of duplicating it.
     */
    public function saveCentre(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => 'nullable|string|max:64',
            'amcos_uuid' => 'required|string|exists:cooperative_amcos,uuid',
            'name' => 'required|string|max:255',
            'centre_code' => 'nullable|string|max:50',
            'default_unit' => 'nullable|string|exists:measurement_units,code',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric|min:0',
            'region_id' => 'nullable|integer|exists:regions,id',
            'district_id' => 'nullable|integer|exists:districts,id',
            'ward_id' => 'nullable|integer|exists:wards,id',
            'village_id' => 'nullable|integer|exists:villages,id',
            'is_active' => 'nullable|boolean',
        ]);
        FarmerProfileFields::assertLocationChain([
            'countryId' => isset($data['region_id']) ? DB::table('regions')->where('id', $data['region_id'])->value('countryId') : null,
            'regionId' => $data['region_id'] ?? null,
            'districtId' => $data['district_id'] ?? null,
            'wardId' => $data['ward_id'] ?? null,
            'villageId' => $data['village_id'] ?? null,
        ]);

        $amcos = DB::table('cooperative_amcos')->where('uuid', $data['amcos_uuid'])->firstOrFail();
        // Any approved AMCOS user/staff can register or edit collection centres for their AMCOS
        CooperativeAccess::requireStaff($request->user()->id, $amcos->uuid, false);
        $uuid = $data['uuid'] ?? (string) Str::uuid();

        try {
            return $this->upsertCentre($request, $data, $amcos, $uuid);
        } catch (UniqueConstraintViolationException $e) {
            // Another upload created this UUID first; retrying takes the update path.
            if (! DB::table('cooperative_collection_centres')->where('uuid', $uuid)->exists()) {
                throw $e;
            }

            return $this->upsertCentre($request, $data, $amcos, $uuid);
        }
    }

    private function upsertCentre(Request $request, array $data, object $amcos, string $uuid): JsonResponse
    {
        $sent = fn (string $key) => array_key_exists($key, $data);

        return DB::transaction(function () use ($request, $data, $sent, $amcos, $uuid) {
            $existing = DB::table('cooperative_collection_centres')->where('uuid', $uuid)->lockForUpdate()->first();
            if ($existing && $existing->amcos_uuid !== $amcos->uuid) {
                CooperativeAccess::deny('This centre belongs to another cooperative.');
            }

            $regionId = $data['region_id'] ?? $amcos->region_id ?? DB::table('regions')->value('id') ?? 1;
            $districtId = $data['district_id']
                ?? $amcos->district_id
                ?? DB::table('districts')->where('regionId', $regionId)->value('id')
                ?? DB::table('districts')->value('id')
                ?? 1;

            $unit = $data['default_unit']
                ?? $existing->default_unit
                ?? $amcos->default_unit
                ?? 'l';
            $openingBalance = round((float) ($data['opening_balance'] ?? ($existing->opening_balance ?? 0)), 2);

            if (! $existing) {
                $code = $data['centre_code'] ?? ('CC-' . strtoupper(Str::random(5)));
                DB::table('cooperative_collection_centres')->insert([
                    'uuid' => $uuid,
                    'mcu_uuid' => $amcos->mcu_uuid,
                    'amcos_uuid' => $amcos->uuid,
                    'default_unit' => $unit,
                    'centre_code' => $code,
                    'name' => trim($data['name']),
                    'region_id' => $regionId,
                    'district_id' => $districtId,
                    'ward_id' => $data['ward_id'] ?? null,
                    'village_id' => $data['village_id'] ?? null,
                    'contact_name' => $data['contact_name'] ?? null,
                    'contact_phone' => $data['contact_phone'] ?? null,
                    'opening_balance' => $openingBalance,
                    'current_balance' => $openingBalance,
                    'total_collected' => 0,
                    'total_dispatched' => 0,
                    'is_active' => $data['is_active'] ?? true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('cooperative_centre_stocks')->insertOrIgnore([
                    'centre_uuid' => $uuid,
                    'commodity_code' => 'milk',
                    'unit' => $unit,
                    'current_balance' => $openingBalance,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($openingBalance > 0) {
                    DB::table('cooperative_centre_stock_ledgers')->insert([
                        'uuid' => (string) Str::uuid(),
                        'unit' => $unit,
                        'mcu_uuid' => $amcos->mcu_uuid,
                        'amcos_uuid' => $amcos->uuid,
                        'centre_uuid' => $uuid,
                        'movement_type' => 'opening_balance',
                        'quantity' => $openingBalance,
                        'running_balance' => $openingBalance,
                        'destination_or_reason' => 'Initial opening centre balance',
                        'recorded_by_user_id' => $request->user()->id,
                        'movement_date' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                DB::table('cooperative_collection_centres')
                    ->where('uuid', $uuid)
                    ->update([
                        'mcu_uuid' => $amcos->mcu_uuid,
                        'amcos_uuid' => $amcos->uuid,
                        'default_unit' => $unit,
                        'name' => trim($data['name']),
                        ...array_filter([
                            'centre_code' => $data['centre_code'] ?? null,
                            'region_id' => $data['region_id'] ?? null,
                            'district_id' => $data['district_id'] ?? null,
                        ], fn ($value) => $value !== null),
                        ...($sent('ward_id') ? ['ward_id' => $data['ward_id']] : []),
                        ...($sent('village_id') ? ['village_id' => $data['village_id']] : []),
                        ...($sent('contact_name') ? ['contact_name' => $data['contact_name']] : []),
                        ...($sent('contact_phone') ? ['contact_phone' => $data['contact_phone']] : []),
                        'is_active' => $data['is_active'] ?? $existing->is_active,
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Collection Centre saved successfully.',
                'data' => DB::table('cooperative_collection_centres')->where('uuid', $uuid)->first(),
            ], $existing ? 200 : 201);
        });
    }
}
