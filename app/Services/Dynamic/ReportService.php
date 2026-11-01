<?php

namespace App\Services\Dynamic;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read models for the Portal and the app. Reports read the summary tables and the
 * ledger only, so every client sees the same numbers.
 *
 * @phpstan-type Scope array{farms: array<int, string>|null, from: ?string, to: ?string}
 */
class ReportService
{
    /** @param Scope $scope */
    public function animals(array $scope): array
    {
        return $this->farms(DB::table('animal_subjects as s')->whereNull('s.deleted_at'), 's.farm_uuid', $scope['farms'])
            ->join('animal_species as sp', 'sp.uuid', '=', 's.species_uuid')
            ->groupBy('sp.code', 'sp.name', 's.unit_type', 's.lifecycle_status')
            ->orderBy('sp.code')
            ->get([
                'sp.code as species_code', 'sp.name as species_name', 's.unit_type', 's.lifecycle_status',
                DB::raw('count(*) as subjects'),
                DB::raw("sum(case when s.unit_type = 'group' then coalesce(s.quantity, 0) else 1 end) as head_count"),
            ])->all();
    }

    /** @param Scope $scope */
    public function events(array $scope): array
    {
        return $this->period($this->farms($this->liveEvents(), 'e.farm_uuid', $scope['farms']), $scope)
            ->leftJoin('behavior_definitions as b', 'b.code', '=', 'e.behavior_code')
            ->leftJoin('event_definitions as d', 'd.uuid', '=', 'e.event_definition_uuid')
            ->groupBy('e.event_code', 'e.behavior_code', DB::raw('coalesce(d.family, b.family)'))
            ->orderByDesc(DB::raw('count(*)'))
            ->get(['e.event_code', 'e.behavior_code', DB::raw('coalesce(d.family, b.family) as family'), DB::raw('count(*) as events'), DB::raw('max(e.occurred_at) as last_occurred_at')])
            ->all();
    }

    /** @param Scope $scope */
    public function reproduction(array $scope): array
    {
        $query = $this->farms(DB::table('subject_reproduction_state as r'), 'r.farm_uuid', $scope['farms'])
            ->join('animal_subjects as s', 's.uuid', '=', 'r.subject_uuid')
            ->when($scope['from'], fn ($q, $from) => $q->where('r.last_birth_at', '>=', $from))
            ->when($scope['to'], fn ($q, $to) => $q->where('r.last_birth_at', '<=', $to.' 23:59:59'));
        $rows = $query->orderByDesc('r.last_birth_at')->get([
            'r.subject_uuid', 's.display_name', 'r.farm_uuid', 'r.births_count', 'r.total_born_alive', 'r.total_born_dead',
            'r.last_birth_at', 'r.last_born_alive', 'r.last_born_dead',
        ]);

        return [
            'totals' => [
                'mothers' => $rows->count(),
                'births' => (int) $rows->sum('births_count'),
                'bornAlive' => (int) $rows->sum('total_born_alive'),
                'bornDead' => (int) $rows->sum('total_born_dead'),
            ],
            'rows' => $rows->all(),
        ];
    }

    /** @param Scope $scope */
    public function production(array $scope): array
    {
        return $this->farms(DB::table('subject_production_daily as p'), 'p.farm_uuid', $scope['farms'])
            ->when($scope['from'], fn ($q, $from) => $q->where('p.production_date', '>=', $from))
            ->when($scope['to'], fn ($q, $to) => $q->where('p.production_date', '<=', $to))
            ->groupBy('p.production_date', 'p.product_code', 'p.unit')
            ->orderByDesc('p.production_date')
            ->get([
                'p.production_date', 'p.product_code', 'p.unit',
                DB::raw('sum(p.quantity) as quantity'), DB::raw('sum(p.damaged_quantity) as damaged_quantity'),
                DB::raw('sum(p.event_count) as events'), DB::raw('count(distinct p.subject_uuid) as subjects'),
            ])->all();
    }

    /** @param Scope $scope */
    public function health(array $scope): array
    {
        $health = fn () => $this->period($this->farms($this->liveEvents(), 'e.farm_uuid', $scope['farms']), $scope)
            ->join('behavior_definitions as b', 'b.code', '=', 'e.behavior_code')
            ->leftJoin('event_definitions as d', 'd.uuid', '=', 'e.event_definition_uuid')
            ->where(fn ($q) => $q->where(DB::raw('coalesce(d.family, b.family)'), 'health')->orWhere('e.event_code', 'like', '%vaccin%')->orWhere('e.event_code', 'like', '%treat%')->orWhere('e.event_code', 'like', '%deworm%'));

        return [
            'byEvent' => $health()->groupBy('e.event_code')->get(['e.event_code', DB::raw('count(*) as events'), DB::raw('count(distinct e.subject_uuid) as subjects')])->all(),
            'recent' => $health()->orderByDesc('e.occurred_at')->limit(100)->get(['e.uuid', 'e.subject_uuid', 'e.event_code', 'e.occurred_at'])->all(),
        ];
    }

    private function liveEvents(): Builder
    {
        return DB::table('event_records as e')->where('e.record_status', '!=', 'voided');
    }

    private function farms(Builder $query, string $column, ?array $farms): Builder
    {
        return $farms === null ? $query : $query->whereIn($column, $farms);
    }

    private function period(Builder $query, array $scope): Builder
    {
        return $query
            ->when($scope['from'], fn ($q, $from) => $q->where('e.occurred_at', '>=', $from))
            ->when($scope['to'], fn ($q, $to) => $q->where('e.occurred_at', '<=', $to.' 23:59:59'));
    }
}
