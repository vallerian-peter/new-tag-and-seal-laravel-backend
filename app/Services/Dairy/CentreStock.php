<?php

namespace App\Services\Dairy;

use Illuminate\Support\Facades\DB;

/**
 * Per-goods stock at a collection centre. Call inside a transaction.
 *
 * The centre's own balance columns still mirror its milk stock, because
 * older app versions read them.
 */
final class CentreStock
{
    /** Unit a goods type is measured in, or null when it is not active. */
    public static function unitOf(string $commodity): ?string
    {
        return DB::table('cooperative_commodities')
            ->where('code', $commodity)
            ->where('is_active', true)
            ->value('default_unit');
    }

    /**
     * Locks the centre's stock row for [commodity]. A missing row starts at
     * zero, or for milk at the centre's own balance.
     */
    public static function lock(string $centreUuid, string $commodity, string $unit): object
    {
        $centre = $commodity === 'milk'
            ? DB::table('cooperative_collection_centres')->where('uuid', $centreUuid)->first()
            : null;
        DB::table('cooperative_centre_stocks')->insertOrIgnore([
            'centre_uuid' => $centreUuid,
            'commodity_code' => $commodity,
            'unit' => $unit,
            'current_balance' => $centre->current_balance ?? 0,
            'total_collected' => $centre->total_collected ?? 0,
            'total_dispatched' => $centre->total_dispatched ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cooperative_centre_stocks')
            ->where('centre_uuid', $centreUuid)
            ->where('commodity_code', $commodity)
            ->lockForUpdate()
            ->first();
    }

    /** Adds the changes to a locked stock row and returns the new values. */
    public static function apply(object $stock, float $balance, float $collected = 0, float $dispatched = 0): object
    {
        $values = [
            'current_balance' => round((float) $stock->current_balance + $balance, 2),
            'total_collected' => round((float) $stock->total_collected + $collected, 2),
            'total_dispatched' => round((float) $stock->total_dispatched + $dispatched, 2),
        ];
        DB::table('cooperative_centre_stocks')->where('id', $stock->id)->update([...$values, 'updated_at' => now()]);
        if ($stock->commodity_code === 'milk') {
            DB::table('cooperative_collection_centres')
                ->where('uuid', $stock->centre_uuid)
                ->update([...$values, 'updated_at' => now()]);
        }

        return (object) [...(array) $stock, ...$values];
    }
}
