<?php

namespace App\Services\Auction;

use App\Enums\UserRole;
use App\Models\AuctionCentre;
use App\Models\AuctionSale;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * "Auction records": every sale an auctioneer or seller can see, with the
 * period graph of summed weight and money, as in the Shamba Bora instant
 * auction dashboard (this week, this month, this year).
 */
class AuctionRecordsService
{
    public const RANGES = ['week', 'month', 'year'];

    /**
     * @param array{range?:string,reference?:string,centreUuid?:string,sessionUuid?:string,status?:string,channel?:string,q?:string} $filters
     */
    public function records(User $user, array $filters = []): array
    {
        $range = in_array($filters['range'] ?? 'week', self::RANGES, true) ? ($filters['range'] ?? 'week') : 'week';
        $reference = ! empty($filters['reference']) ? Carbon::parse($filters['reference']) : now();
        [$from, $to] = self::window($range, $reference);

        $q = AuctionSale::with(['lot', 'session.centre'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($user->role !== UserRole::SYSTEM_USER) {
            $q->where(function ($w) use ($user) {
                $w->where('sellerUserId', $user->id)
                    ->orWhere('recordedByUserId', $user->id)
                    ->orWhere('buyerUserId', $user->id)
                    ->orWhereHas('session', fn ($s) => $s->where('hostUserId', $user->id));
            });
        }
        if (! empty($filters['centreUuid'])) {
            $centreId = AuctionCentre::where('uuid', $filters['centreUuid'])->value('id') ?? 0;
            $q->whereHas('session', fn ($s) => $s->where('centreId', $centreId));
        }
        if (! empty($filters['sessionUuid'])) {
            $q->whereHas('session', fn ($s) => $s->where('uuid', $filters['sessionUuid']));
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['channel'])) {
            $q->where('channel', $filters['channel']);
        }
        if (! empty($filters['q'])) {
            $like = '%'.$filters['q'].'%';
            $q->where(function ($w) use ($like) {
                $w->where('receiptNo', 'like', $like)
                    ->orWhere('buyerName', 'like', $like)
                    ->orWhere('buyerPhone', 'like', $like)
                    ->orWhereHas('lot', fn ($l) => $l->where('title', 'like', $like)->orWhere('livestockUuid', 'like', $like));
            });
        }

        $sales = $q->orderByDesc('created_at')->limit(1000)->get();
        $buckets = self::buckets($range, $from);
        foreach ($sales as $sale) {
            $key = self::bucketKey($range, $sale->created_at, $from);
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['count']++;
            $buckets[$key]['weightKg'] += (float) ($sale->weightKg ?? 0);
            $buckets[$key]['amount'] += (float) $sale->amount;
        }

        return [
            'range' => $range,
            'fromDate' => $from->toDateString(),
            'toDate' => $to->toDateString(),
            'graph' => array_values(array_map(fn ($b) => [
                'label' => $b['label'],
                'count' => $b['count'],
                'weightKg' => round($b['weightKg'], 2),
                'amount' => round($b['amount'], 2),
            ], $buckets)),
            'totals' => [
                'count' => $sales->count(),
                'sold' => $sales->count(),
                'weightKg' => round((float) $sales->sum(fn ($s) => (float) ($s->weightKg ?? 0)), 2),
                'amount' => round((float) $sales->sum('amount'), 2),
                'paid' => $sales->where('status', 'paid')->count(),
            ],
            'records' => $sales->map(fn (AuctionSale $s) => $this->row($s, $user))->values()->all(),
        ];
    }

    public function row(AuctionSale $s, User $viewer): array
    {
        $data = $s->toWire($viewer->id, true);
        $data['sessionMode'] = $s->session?->mode;
        $data['centre'] = $s->session?->centre?->summary();

        return $data;
    }

    /** Sunday-to-Saturday weeks, calendar months, calendar years. */
    public static function window(string $range, Carbon $reference): array
    {
        $day = $reference->copy()->startOfDay();

        return match ($range) {
            'month' => [$day->copy()->startOfMonth(), $day->copy()->endOfMonth()->startOfDay()],
            'year' => [$day->copy()->startOfYear(), $day->copy()->endOfYear()->startOfDay()],
            default => [
                $day->copy()->subDays($day->dayOfWeek), // Carbon: Sunday = 0
                $day->copy()->subDays($day->dayOfWeek)->addDays(6),
            ],
        };
    }

    private static function buckets(string $range, Carbon $from): array
    {
        $out = [];
        switch ($range) {
            case 'month':
                $days = $from->daysInMonth;
                for ($w = 1; $w <= (int) ceil($days / 7); $w++) {
                    $out["wk$w"] = ['label' => "Wk$w", 'count' => 0, 'weightKg' => 0.0, 'amount' => 0.0];
                }
                break;
            case 'year':
                for ($m = 1; $m <= 12; $m++) {
                    $out["m$m"] = ['label' => Carbon::create(null, $m, 1)->format('M'), 'count' => 0, 'weightKg' => 0.0, 'amount' => 0.0];
                }
                break;
            default:
                foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $label) {
                    $out["d$i"] = ['label' => $label, 'count' => 0, 'weightKg' => 0.0, 'amount' => 0.0];
                }
        }

        return $out;
    }

    private static function bucketKey(string $range, Carbon $at, Carbon $from): string
    {
        return match ($range) {
            'month' => 'wk'.((int) ceil($at->day / 7)),
            'year' => 'm'.$at->month,
            default => 'd'.$at->dayOfWeek,
        };
    }
}
