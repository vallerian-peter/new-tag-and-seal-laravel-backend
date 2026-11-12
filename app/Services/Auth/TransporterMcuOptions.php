<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unions a transporter can apply under. A one-region union gives the company its region;
 * an all-regions union lists its country's regions for the company to pick from.
 */
class TransporterMcuOptions
{
    public static function all(): array
    {
        $mcus = DB::table('cooperative_mcus as m')
            ->leftJoin('regions as r', 'r.id', '=', 'm.region_id')
            ->where('m.is_active', true)
            ->orderBy('m.name')
            ->get(['m.uuid', 'm.name', 'm.country_id', 'm.all_regions', 'm.region_id', 'r.name as region_name']);
        $countries = $mcus->where('all_regions', true)->pluck('country_id')->filter()->unique();
        $regions = DB::table('regions')->whereIn('countryId', $countries)->orderBy('name')
            ->get(['id', 'name', 'countryId'])->groupBy('countryId');

        return $mcus->map(fn ($m) => [
            'uuid' => $m->uuid,
            'name' => $m->name,
            'all_regions' => (bool) $m->all_regions,
            'region_id' => $m->all_regions ? null : $m->region_id,
            'region_name' => $m->all_regions ? null : $m->region_name,
            'regions' => $m->all_regions
                ? ($regions->get($m->country_id) ?? collect())->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all()
                : [],
        ])->all();
    }

    /** The region a company under this union operates in: the union's own, or the one chosen from its country. */
    public static function regionFor(string $mcuUuid, mixed $chosen): ?int
    {
        $mcu = DB::table('cooperative_mcus')->where('uuid', $mcuUuid)->first(['all_regions', 'country_id', 'region_id']);
        if (! $mcu->all_regions) {
            return $mcu->region_id;
        }

        return DB::table('regions')->where('id', (int) $chosen)->where('countryId', $mcu->country_id)->value('id')
            ?? throw ValidationException::withMessages(['region_id' => 'Choose a region this union covers.']);
    }
}
