<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnimalSubject;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\User;
use App\Services\Admin\AnalyticsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function __invoke(): JsonResponse
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $previousMonthStart = $monthStart->copy()->subMonth();
        $previousMonthEnd = $monthStart->copy()->subSecond();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $sixtyDaysAgo = $now->copy()->subDays(60);

        $animalTotal = Schema::hasTable('animal_subjects')
            ? (int) AnimalSubject::query()->whereNull('deleted_at')->selectRaw("coalesce(sum(case when unit_type = 'group' then coalesce(quantity, 0) else 1 end), 0) as total")->value('total')
            : 0;

        $registryStats = [
            $this->stat('animals', 'Registered animals', $animalTotal, $this->createdCount('animal_subjects', $monthStart), $this->createdCount('animal_subjects', $previousMonthStart, $previousMonthEnd), 'registry'),
            $this->stat('farms', 'Active farms', Schema::hasTable('farms') ? Farm::query()->where('status', 'active')->count() : 0, $this->createdCount('farms', $monthStart), $this->createdCount('farms', $previousMonthStart, $previousMonthEnd), 'registry'),
            $this->stat('farmers', 'Active farmers', Schema::hasTable('farmers') ? Farmer::query()->where('status', 'active')->count() : 0, $this->createdCount('farmers', $monthStart), $this->createdCount('farmers', $previousMonthStart, $previousMonthEnd), 'registry'),
            $this->stat('users', 'Active user accounts', Schema::hasTable('users') ? User::query()->where('status', 'active')->count() : 0, $this->createdCount('users', $monthStart), $this->createdCount('users', $previousMonthStart, $previousMonthEnd), 'registry'),
        ];

        $milking = Schema::hasTable('milkings')
            ? DB::table('milkings')->selectRaw('count(*) as events, coalesce(sum(amount), 0) as litres')->first()
            : null;
        $milkCurrent = Schema::hasTable('milkings') ? (float) DB::table('milkings')->where('eventDate', '>=', $monthStart)->sum('amount') : 0;
        $milkPrevious = Schema::hasTable('milkings') ? (float) DB::table('milkings')->whereBetween('eventDate', [$previousMonthStart, $previousMonthEnd])->sum('amount') : 0;

        $vaccinations = $this->eventCount(fn ($query) => $query->where('event_code', 'like', '%vaccin%'));
        $vaccinationsCurrent = $this->eventCount(fn ($query) => $query->where('event_code', 'like', '%vaccin%')->where('occurred_at', '>=', $thirtyDaysAgo));
        $vaccinationsPrevious = $this->eventCount(fn ($query) => $query->where('event_code', 'like', '%vaccin%')->whereBetween('occurred_at', [$sixtyDaysAgo, $thirtyDaysAgo]));
        $birthScope = fn ($query) => $query->where(fn ($birthQuery) => $birthQuery->where('behavior_code', 'record_birth')->orWhere('event_code', 'like', '%birth%')->orWhere('event_code', 'like', '%calving%')->orWhere('event_code', 'like', '%farrowing%')->orWhere('event_code', 'like', '%hatching%'));
        $births = $this->eventCount($birthScope);
        $birthsCurrent = $this->eventCount(fn ($query) => $birthScope($query->where('occurred_at', '>=', $thirtyDaysAgo)));
        $birthsPrevious = $this->eventCount(fn ($query) => $birthScope($query->whereBetween('occurred_at', [$sixtyDaysAgo, $thirtyDaysAgo])));

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $systemHealthy = $failedJobs === 0;

        $eventStats = [
            $this->eventStat('milking', 'Milk recorded', (float) ($milking->litres ?? 0), $milkCurrent, $milkPrevious, 'L', 'All-time recorded milk volume'),
            $this->eventStat('vaccinations', 'Vaccinations', $vaccinations, $vaccinationsCurrent, $vaccinationsPrevious, null, 'All-time vaccination event count'),
            $this->eventStat('births', 'Birth events', $births, $birthsCurrent, $birthsPrevious, null, 'All-time birth event count'),
            ['key' => 'system-health', 'label' => 'System health', 'value' => $systemHealthy ? 100 : 70, 'displayValue' => $systemHealthy ? 'Healthy' : 'Needs attention', 'change' => null, 'direction' => $systemHealthy ? 'up' : 'down', 'category' => 'system', 'note' => $failedJobs.' failed · '.$pendingJobs.' queued jobs'],
        ];

        $months = collect(range(5, 0))->map(fn (int $offset) => $now->copy()->startOfMonth()->subMonths($offset));
        $rangeStart = $months->first()->copy()->startOfMonth();
        $periodExpr = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "date_format(created_at, '%Y-%m')";
        $occurredPeriodExpr = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', occurred_at)"
            : "date_format(occurred_at, '%Y-%m')";
        $animalTrendRows = Schema::hasTable('animal_subjects')
            ? DB::table('animal_subjects')->whereNull('deleted_at')->where('created_at', '>=', $rangeStart)->selectRaw("{$periodExpr} as period")->selectRaw("sum(case when unit_type = 'group' then coalesce(quantity, 0) else 1 end) as value")->groupBy('period')->pluck('value', 'period')
            : collect();
        $eventTrendRows = Schema::hasTable('event_records')
            ? DB::table('event_records')->where('record_status', '!=', 'voided')->where('occurred_at', '>=', $rangeStart)->selectRaw("{$occurredPeriodExpr} as period, count(*) as value")->groupBy('period')->pluck('value', 'period')
            : collect();
        $animalTrend = $months->map(fn (Carbon $month) => ['month' => $month->format('M'), 'value' => (float) ($animalTrendRows[$month->format('Y-m')] ?? 0)])->values();
        $eventTrend = $months->map(fn (Carbon $month) => ['month' => $month->format('M'), 'value' => (int) ($eventTrendRows[$month->format('Y-m')] ?? 0)])->values();

        $species = collect();
        if (Schema::hasTable('animal_subjects') && Schema::hasTable('animal_species')) {
            $species = DB::table('animal_subjects as subjects')->join('animal_species as species', 'species.uuid', '=', 'subjects.species_uuid')->whereNull('subjects.deleted_at')->groupBy('species.uuid', 'species.name')->orderByDesc('value')->get(['species.name', DB::raw("sum(case when subjects.unit_type = 'group' then coalesce(subjects.quantity, 0) else 1 end) as value")])->map(fn ($row) => ['name' => $row->name, 'value' => (int) $row->value]);
        }

        $healthByEvent = Schema::hasTable('event_records')
            ? DB::table('event_records')->where('record_status', '!=', 'voided')->where(fn ($query) => $query->where('event_code', 'like', '%health%')->orWhere('event_code', 'like', '%vaccin%')->orWhere('event_code', 'like', '%treat%')->orWhere('event_code', 'like', '%medication%')->orWhere('event_code', 'like', '%deworm%')->orWhere('event_code', 'like', '%disease%'))->selectRaw('event_code as name, count(*) as value')->groupBy('event_code')->orderByDesc('value')->limit(6)->get()->map(fn ($row) => ['name' => $row->name ?: 'Health event', 'value' => (int) $row->value])
            : collect();
        $recentActivity = Schema::hasTable('event_records')
            ? DB::table('event_records')->where('record_status', '!=', 'voided')->orderByDesc('occurred_at')->limit(8)->get(['uuid', 'event_code', 'subject_uuid', 'farm_uuid', 'occurred_at', 'record_status'])->map(fn ($row) => ['id' => $row->uuid, 'title' => str($row->event_code ?: 'Animal event')->replace(['_', '-'], ' ')->title()->toString(), 'subjectUuid' => $row->subject_uuid, 'farmUuid' => $row->farm_uuid, 'occurredAt' => $row->occurred_at ? Carbon::parse($row->occurred_at)->toIso8601String() : null, 'status' => $row->record_status])
            : collect();

        $signals = $this->analytics->dashboardSignals(
            CarbonImmutable::instance($now->copy()->subDays(29)->startOfDay()),
            CarbonImmutable::instance($now->copy()->endOfDay()),
        );

        return response()->json(['status' => true, 'data' => ['stats' => [...$registryStats, ...$eventStats], 'animalTrend' => $animalTrend, 'eventTrend' => $eventTrend, 'species' => $species, 'healthByEvent' => $healthByEvent, 'recentActivity' => $recentActivity, ...$signals, 'generatedAt' => $now->toIso8601String()]]);
    }

    private function stat(string $key, string $label, int $value, int $current, int $previous, string $category): array
    {
        $change = $this->change($current, $previous);

        return ['key' => $key, 'label' => $label, 'value' => $value, 'periodValue' => $current, 'periodLabel' => 'this month', 'change' => $change, 'direction' => $this->direction($change), 'category' => $category];
    }

    private function eventStat(string $key, string $label, int|float $value, int|float $current, int|float $previous, ?string $unit, string $note): array
    {
        $change = $this->change($current, $previous);

        return ['key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit, 'change' => $change, 'direction' => $this->direction($change), 'category' => 'event', 'note' => $note];
    }

    private function change(int|float $current, int|float $previous): ?float
    {
        if ($previous == 0) {
            return $current == 0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function direction(?float $change): string
    {
        if ($change === null) {
            return 'neutral';
        }

        return $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral');
    }

    private function createdCount(string $table, Carbon $from, ?Carbon $to = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->where('created_at', '>=', $from)->when($to, fn ($query) => $query->where('created_at', '<=', $to))->count();
    }

    private function eventCount(callable $scope): int
    {
        if (! Schema::hasTable('event_records')) {
            return 0;
        }
        $query = DB::table('event_records')->where('record_status', '!=', 'voided');
        $scope($query);

        return $query->count();
    }
}
