<?php

namespace App\Services\Dairy;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Incremental pull pages ordered by (updated_at, id).
 *
 * The cursor is "Y-m-d H:i:s|id". On the last page it is moved back a few
 * seconds so rows written in the same second as the last pull are read again;
 * clients merge by UUID, so re-reading is harmless and nothing is skipped.
 */
class SyncCursor
{
    public const PAGE_SIZE = 500;

    private const OVERLAP_SECONDS = 5;

    /** @return array{0: Collection, 1: array{cursor: string, has_more: bool}} */
    public static function page(Builder $query, string $alias, ?string $cursor, int $limit = self::PAGE_SIZE): array
    {
        [$since, $afterId] = self::parse($cursor);
        if ($since !== null) {
            $query->where(function ($q) use ($alias, $since, $afterId) {
                $q->where("{$alias}.updated_at", '>', $since)
                    ->orWhere(fn ($same) => $same->where("{$alias}.updated_at", $since)->where("{$alias}.id", '>', $afterId));
            });
        }
        $rows = $query->reorder()->orderBy("{$alias}.updated_at")->orderBy("{$alias}.id")->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $last = $rows->last();

        if ($last === null) {
            $next = (string) $cursor;
        } elseif ($hasMore) {
            $next = Carbon::parse($last->updated_at)->format('Y-m-d H:i:s').'|'.$last->id;
        } else {
            $next = Carbon::parse($last->updated_at)->subSeconds(self::OVERLAP_SECONDS)->format('Y-m-d H:i:s').'|0';
        }

        return [$rows, ['cursor' => $next, 'has_more' => $hasMore]];
    }

    /** @return array{0: ?string, 1: int} */
    private static function parse(?string $cursor): array
    {
        if (! $cursor || ! preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $cursor, $match)) {
            return [null, 0];
        }

        return [$match[1], (int) $match[2]];
    }
}
