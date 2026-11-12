<?php

namespace App\Services\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsService
{
    private const FAMILIES = ['health', 'production', 'reproduction', 'movement', 'growth', 'other'];

    public function dashboardSignals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $filters = ['countryId' => null, 'regionId' => null, 'farmUuid' => null];

        return [
            'activityTrend' => $this->activityTrend($from, $to, $filters),
            'productionTrend' => $this->productionTrend($from, $to, $filters),
            'attention' => $this->attention($filters),
        ];
    }

    /**
     * @param  array{countryId: ?int, regionId: ?int, farmUuid: ?string}  $filters
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subSecond();
        $previousFrom = $previousTo->subDays($days - 1)->startOfDay();

        $activity = $this->activityTrend($from, $to, $filters);
        $production = $this->productionTrend($from, $to, $filters);
        $livestock = $this->livestockRows($filters);
        $events = $this->eventRows($from, $to, $filters);
        $eventDetails = $this->eventDetailRows($from, $to, $filters);
        $regions = $this->regionRows($from, $to, $filters);
        $countries = $this->countryRows($from, $to, $filters);
        $productionRows = $this->productionRows($from, $to, $filters);
        $health = $events->filter(fn (object $row) => $this->family((string) ($row->family ?? ''), (string) $row->eventCode) === 'health')->values();
        $farms = $this->farmRows($from, $to, $filters);
        $farmers = $this->farmerRows($from, $to, $filters);
        $farmUsers = $this->farmUserRows();
        $extensionOfficers = $this->extensionOfficerRows();
        $farmInvites = $this->farmInviteRows();
        $userAccounts = $this->userAccountRows();
        $systemUsers = $this->systemUserRows();
        $finance = $this->financeRows($from, $to, $filters);
        $bills = $this->billRows($from, $to, $filters);
        $milkings = $this->milkingRows($from, $to, $filters);
        $additionalReports = $this->additionalReports($from, $to, $filters);

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previousFrom' => $previousFrom->toDateString(),
                'previousTo' => $previousTo->toDateString(),
                'days' => $days,
            ],
            'filters' => $this->filterOptions(),
            'stats' => $this->stats($from, $to, $previousFrom, $previousTo, $filters),
            'activityTrend' => $activity,
            'productionTrend' => $production,
            'animalsBySpecies' => $livestock->groupBy('speciesName')->map(function (Collection $rows, string $name): array {
                return ['name' => $name, 'value' => (int) $rows->sum('headCount')];
            })->sortByDesc('value')->values()->all(),
            'eventsByFamily' => collect(self::FAMILIES)->map(function (string $family) use ($events): array {
                return ['name' => $family, 'value' => (int) $events->filter(fn (object $row) => $this->family((string) ($row->family ?? ''), (string) $row->eventCode) === $family)->sum('events')];
            })->filter(fn (array $row) => $row['value'] > 0)->values()->all(),
            'eventTypes' => $events->map(fn (object $row): array => ['value' => $row->eventCode, 'label' => $row->eventName, 'count' => $row->events])->values()->all(),
            'reportCatalog' => $this->reportCatalog(),
            'milkingAnalysis' => $this->milkingAnalysis($milkings, $from, $to),
            'topFarms' => $farms->sortByDesc(fn (object $row) => ((int) $row->events * 2) + (int) $row->animals)->take(8)->values()->all(),
            'attention' => $this->attention($filters),
            'regionalCoverage' => ['columns' => ['country', 'region', 'farms', 'animals', 'events'], 'rows' => $regions->all()],
            'productionRecords' => ['columns' => ['productionDate', 'productCode', 'unit', 'quantity', 'damagedQuantity', 'events', 'subjects'], 'rows' => $productionRows->all()],
            'healthSummary' => ['columns' => ['eventCode', 'events', 'subjects', 'lastOccurredAt'], 'rows' => $health->all()],
            'reports' => [
                'livestock' => ['columns' => ['speciesName', 'unitType', 'lifecycleStatus', 'subjects', 'headCount'], 'rows' => $livestock->all()],
                'events' => ['columns' => ['eventName', 'subject', 'species', 'farmName', 'occurredAt', 'status'], 'rows' => $eventDetails->all()],
                'milkings' => ['columns' => ['eventDate', 'farmName', 'farmerName', 'animalName', 'identificationNumber', 'milkingMethod', 'session', 'litres', 'status'], 'rows' => $milkings->all()],
                'farms' => ['columns' => ['referenceNo', 'farmName', 'ownerName', 'size', 'sizeUnit', 'country', 'region', 'status', 'animals', 'events'], 'rows' => $farms->all()],
                'farmers' => ['columns' => ['farmerNo', 'farmerName', 'phone', 'status', 'farms', 'animals', 'events', 'lastActivity'], 'rows' => $farmers->all()],
                'farmUsers' => ['columns' => ['fullName', 'phone', 'email', 'roleTitle', 'gender', 'status', 'createdAt'], 'rows' => $farmUsers->all()],
                'extensionOfficers' => ['columns' => ['fullName', 'phone', 'email', 'organization', 'specialization', 'verified', 'invites'], 'rows' => $extensionOfficers->all()],
                'farmInvites' => ['columns' => ['extensionOfficer', 'farmerName', 'email', 'accessCode', 'createdAt'], 'rows' => $farmInvites->all()],
                'userAccounts' => ['columns' => ['username', 'email', 'role', 'accountStatus', 'createdAt'], 'rows' => $userAccounts->all()],
                'systemUsers' => ['columns' => ['fullName', 'phone', 'address', 'status', 'createdAt'], 'rows' => $systemUsers->all()],
                'countries' => ['columns' => ['country', 'regions', 'farms', 'animals', 'events'], 'rows' => $countries->all()],
                'finance' => ['columns' => ['transactionType', 'reference', 'farmName', 'source', 'amount', 'status', 'transactionDate'], 'rows' => $finance->all()],
                'bills' => ['columns' => ['billNo', 'farmName', 'farmerName', 'subjectType', 'quantity', 'amount', 'status', 'billDate'], 'rows' => $bills->all()],
                ...$additionalReports,
            ],
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    private function stats(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $previousFrom, CarbonImmutable $previousTo, array $filters): array
    {
        $currentEvents = $this->eventCount($from, $to, $filters);
        $previousEvents = $this->eventCount($previousFrom, $previousTo, $filters);
        $currentRegistrations = $this->animalRegistrationCount($from, $to, $filters);
        $previousRegistrations = $this->animalRegistrationCount($previousFrom, $previousTo, $filters);
        $milkCurrent = $this->productionQuantity($from, $to, $filters, 'milk');
        $milkPrevious = $this->productionQuantity($previousFrom, $previousTo, $filters, 'milk');
        $birthCurrent = $this->familyEventCount($from, $to, $filters, 'birth');
        $birthPrevious = $this->familyEventCount($previousFrom, $previousTo, $filters, 'birth');
        $healthCurrent = $this->familyEventCount($from, $to, $filters, 'health');
        $healthPrevious = $this->familyEventCount($previousFrom, $previousTo, $filters, 'health');
        $activeFarms = $this->farmScope($filters)->where('f.status', 'active')->count();

        return [
            $this->stat('animals', 'Animals registered', $currentRegistrations, $previousRegistrations, 'During the selected period'),
            $this->stat('events', 'Recorded events', $currentEvents, $previousEvents, 'Non-voided ledger activity'),
            $this->stat('farms', 'Active farms', $activeFarms, null, 'Current scoped coverage'),
            $this->stat('milk', 'Milk production', $milkCurrent, $milkPrevious, 'Selected period', 'L'),
            $this->stat('births', 'Birth activity', $birthCurrent, $birthPrevious, 'Births, calvings and farrowings'),
            $this->stat('health', 'Health interventions', $healthCurrent, $healthPrevious, 'Vaccination, treatment and deworming'),
        ];
    }

    private function activityTrend(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $daily = $from->diffInDays($to) <= 45;
        $expression = $this->periodExpression('e.occurred_at', $daily);
        $rows = $this->eventScope($from, $to, $filters)
            ->leftJoin('behavior_definitions as b', 'b.code', '=', 'e.behavior_code')
            ->leftJoin('event_definitions as d', 'd.uuid', '=', 'e.event_definition_uuid')
            ->selectRaw("{$expression} as period")
            ->selectRaw("coalesce(d.family, b.family, 'other') as family")
            ->selectRaw('count(*) as value')
            ->groupBy('period', 'family')
            ->get();

        $periods = $this->periods($from, $to, $daily);
        $indexed = $rows->groupBy('period');

        return $periods->map(function (array $period) use ($indexed): array {
            $point = ['period' => $period['key'], 'label' => $period['label'], ...array_fill_keys(self::FAMILIES, 0), 'total' => 0];
            foreach ($indexed->get($period['key'], collect()) as $row) {
                $family = $this->family((string) $row->family);
                $point[$family] += (int) $row->value;
                $point['total'] += (int) $row->value;
            }

            return $point;
        })->all();
    }

    private function productionTrend(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        if (! Schema::hasTable('subject_production_daily')) {
            return [];
        }
        $daily = $from->diffInDays($to) <= 45;
        $expression = $this->periodExpression('p.production_date', $daily);
        $rows = DB::table('subject_production_daily as p')
            ->whereIn('p.farm_uuid', $this->farmIds($filters))
            ->whereBetween('p.production_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("{$expression} as period")
            ->selectRaw('sum(p.quantity) as quantity')
            ->selectRaw('sum(p.damaged_quantity) as damaged')
            ->groupBy('period')
            ->pluck('quantity', 'period');

        return $this->periods($from, $to, $daily)->map(fn (array $period): array => [
            'period' => $period['key'],
            'label' => $period['label'],
            'quantity' => (float) ($rows[$period['key']] ?? 0),
        ])->all();
    }

    private function livestockRows(array $filters): Collection
    {
        if (! Schema::hasTable('animal_subjects')) {
            return collect();
        }

        return DB::table('animal_subjects as s')
            ->join('animal_species as sp', 'sp.uuid', '=', 's.species_uuid')
            ->whereNull('s.deleted_at')
            ->whereIn('s.farm_uuid', $this->farmIds($filters))
            ->groupBy('sp.name', 's.unit_type', 's.lifecycle_status')
            ->orderBy('sp.name')
            ->get([
                'sp.name as speciesName', 's.unit_type as unitType', 's.lifecycle_status as lifecycleStatus',
                DB::raw('count(*) as subjects'),
                DB::raw("sum(case when s.unit_type = 'group' then coalesce(s.quantity, 0) else 1 end) as headCount"),
            ])->map(fn (object $row): object => (object) [
                'speciesName' => $row->speciesName,
                'unitType' => $row->unitType,
                'lifecycleStatus' => $row->lifecycleStatus,
                'subjects' => (int) $row->subjects,
                'headCount' => (int) $row->headCount,
            ]);
    }

    private function eventRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        if (! Schema::hasTable('event_records')) {
            return collect();
        }

        return $this->eventScope($from, $to, $filters)
            ->leftJoin('behavior_definitions as b', 'b.code', '=', 'e.behavior_code')
            ->leftJoin('event_definitions as d', 'd.uuid', '=', 'e.event_definition_uuid')
            ->groupBy('e.event_code', DB::raw("coalesce(d.family, b.family, 'other')"))
            ->orderByDesc(DB::raw('count(*)'))
            ->get([
                'e.event_code as eventCode', DB::raw('max(coalesce(d.name, e.event_code)) as eventName'), DB::raw("coalesce(d.family, b.family, 'other') as family"),
                DB::raw('count(*) as events'), DB::raw('count(distinct e.subject_uuid) as subjects'), DB::raw('max(e.occurred_at) as lastOccurredAt'),
            ])->map(fn (object $row): object => (object) [
                'eventCode' => $row->eventCode,
                'eventName' => $row->eventName,
                'family' => $this->family((string) $row->family, (string) $row->eventCode),
                'events' => (int) $row->events,
                'subjects' => (int) $row->subjects,
                'lastOccurredAt' => $row->lastOccurredAt,
            ]);
    }

    private function eventDetailRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        if (! Schema::hasTable('event_records')) {
            return collect();
        }

        return $this->eventScope($from, $to, $filters)
            ->leftJoin('event_definitions as d', 'd.uuid', '=', 'e.event_definition_uuid')
            ->leftJoin('animal_subjects as s', 's.uuid', '=', 'e.subject_uuid')
            ->leftJoin('animal_species as sp', 'sp.uuid', '=', 's.species_uuid')
            ->leftJoin('farms as f', 'f.uuid', '=', 'e.farm_uuid')
            ->orderByDesc('e.occurred_at')
            ->limit(1000)
            ->get([
                'e.event_code as eventCode', DB::raw('coalesce(d.name, e.event_code) as eventName'),
                DB::raw("coalesce(s.display_name, e.subject_uuid, 'Unassigned') as subject"), DB::raw("coalesce(sp.name, 'Unassigned') as species"),
                DB::raw("coalesce(f.name, 'Unassigned') as farmName"), 'e.occurred_at as occurredAt', 'e.record_status as status',
            ]);
    }

    private function productionRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        if (! Schema::hasTable('subject_production_daily')) {
            return collect();
        }

        return DB::table('subject_production_daily as p')
            ->whereIn('p.farm_uuid', $this->farmIds($filters))
            ->whereBetween('p.production_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('p.production_date', 'p.product_code', 'p.unit')
            ->orderByDesc('p.production_date')
            ->limit(500)
            ->get([
                'p.production_date as productionDate', 'p.product_code as productCode', 'p.unit',
                DB::raw('sum(p.quantity) as quantity'), DB::raw('sum(p.damaged_quantity) as damagedQuantity'),
                DB::raw('sum(p.event_count) as events'), DB::raw('count(distinct p.subject_uuid) as subjects'),
            ]);
    }

    private function milkingRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        if (! Schema::hasTable('milkings')) {
            return collect();
        }

        return DB::table('milkings as milk')
            ->leftJoin('farms as f', 'f.uuid', '=', 'milk.farmUuid')
            ->leftJoin('farmers as owner', 'owner.id', '=', 'f.farmerId')
            ->leftJoin('livestocks as animal', 'animal.uuid', '=', 'milk.livestockUuid')
            ->leftJoin('milking_methods as method', 'method.id', '=', 'milk.milkingMethodId')
            ->whereIn('milk.farmUuid', $this->farmIds($filters))
            ->whereBetween(DB::raw('coalesce(milk.eventDate, milk.created_at)'), [$from, $to])
            ->orderByDesc(DB::raw('coalesce(milk.eventDate, milk.created_at)'))
            ->limit(3000)
            ->get([
                DB::raw('coalesce(milk.eventDate, milk.created_at) as eventDate'), DB::raw("coalesce(f.name, 'Unassigned') as farmName"),
                'owner.firstName', 'owner.middleName', 'owner.surname', DB::raw("coalesce(animal.name, animal.identificationNumber, 'Unassigned') as animalName"),
                'animal.identificationNumber', DB::raw("coalesce(method.name, 'Unspecified') as milkingMethod"), 'milk.session', 'milk.amount as litres', 'milk.status',
            ])->map(function (object $row): object {
                $row->farmerName = trim(implode(' ', array_filter([$row->firstName, $row->middleName, $row->surname]))) ?: 'Unassigned';
                $row->litres = (float) $row->litres;
                unset($row->firstName, $row->middleName, $row->surname);

                return $row;
            });
    }

    private function milkingAnalysis(Collection $rows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $breakdown = fn (string $key): array => $rows->groupBy($key)->map(fn (Collection $items, string $name): array => ['name' => $name ?: 'Unassigned', 'value' => round((float) $items->sum('litres'), 2)])->sortByDesc('value')->values()->all();
        $daily = $rows->groupBy(fn (object $row): string => CarbonImmutable::parse($row->eventDate)->toDateString())->map(fn (Collection $items): float => round((float) $items->sum('litres'), 2));
        $total = round((float) $rows->sum('litres'), 2);

        return [
            'totalLitres' => $total,
            'records' => $rows->count(),
            'averageLitres' => $rows->count() ? round($total / $rows->count(), 2) : 0,
            'byFarm' => $breakdown('farmName'),
            'byFarmer' => $breakdown('farmerName'),
            'byAnimal' => $breakdown('animalName'),
            'trend' => $this->periods($from, $to, true)->map(fn (array $period): array => ['period' => $period['key'], 'label' => $period['label'], 'quantity' => (float) ($daily[$period['key']] ?? 0)])->all(),
        ];
    }

    private function reportCatalog(): array
    {
        return [
            ['value' => 'livestock', 'label' => 'Livestock Registry', 'group' => 'Livestock & identification', 'description' => 'Registered individual and group livestock by species and lifecycle status.'],
            ['value' => 'events', 'label' => 'Event Records', 'group' => 'Livestock & identification', 'description' => 'Choose one event type, then review only records of that type.'],
            ['value' => 'identifiers', 'label' => 'Animal Identifiers', 'group' => 'Livestock & identification', 'description' => 'Tags and identifiers assigned to animal records.'],
            ['value' => 'eventDefinitions', 'label' => 'Event Catalogue', 'group' => 'Livestock & identification', 'description' => 'Configured event definitions, families and publication status.'],
            ['value' => 'milkings', 'label' => 'Milking Records', 'group' => 'Production', 'description' => 'Detailed milk sessions with printable comparisons by farm, farmer and animal.'],
            ['value' => 'farmers', 'label' => 'Farmers', 'group' => 'People & organizations', 'description' => 'Farmer coverage, farms, livestock and activity.'],
            ['value' => 'farms', 'label' => 'Farms', 'group' => 'People & organizations', 'description' => 'Farm ownership, size, status, livestock and events.'],
            ['value' => 'farmUsers', 'label' => 'Farm Users', 'group' => 'People & organizations', 'description' => 'People assigned to farm operations and their roles.'],
            ['value' => 'extensionOfficers', 'label' => 'Extension Officers', 'group' => 'People & organizations', 'description' => 'Officer profiles, specializations, verification and invite coverage.'],
            ['value' => 'farmInvites', 'label' => 'Farm Invites', 'group' => 'People & organizations', 'description' => 'Extension officer access issued to farmer operations.'],
            ['value' => 'userAccounts', 'label' => 'User Accounts', 'group' => 'Accounts & access', 'description' => 'Login accounts, roles and account status.'],
            ['value' => 'systemUsers', 'label' => 'System Users', 'group' => 'Accounts & access', 'description' => 'Administrative user profiles.'],
            ['value' => 'finance', 'label' => 'Financial Transactions', 'group' => 'Finance', 'description' => 'Income and expenditure transactions for the selected period.'],
            ['value' => 'bills', 'label' => 'Bills', 'group' => 'Finance', 'description' => 'Billing records, amounts, subjects and payment status.'],
            ['value' => 'countries', 'label' => 'Country Coverage', 'group' => 'Geography', 'description' => 'Countries, regions, farms, livestock and event coverage.'],
            ['value' => 'regions', 'label' => 'Regions', 'group' => 'Geography', 'description' => 'Configured regions and their parent countries.'],
            ['value' => 'districts', 'label' => 'Districts', 'group' => 'Geography', 'description' => 'Configured districts and their parent regions.'],
            ['value' => 'wards', 'label' => 'Wards', 'group' => 'Geography', 'description' => 'Configured wards and their parent districts.'],
            ['value' => 'villages', 'label' => 'Villages', 'group' => 'Geography', 'description' => 'Configured villages and their parent wards.'],
            ['value' => 'species', 'label' => 'Species', 'group' => 'Reference data', 'description' => 'Livestock species available during registration.'],
            ['value' => 'breeds', 'label' => 'Breeds', 'group' => 'Reference data', 'description' => 'Breed catalogue and livestock-type grouping.'],
            ['value' => 'vaccines', 'label' => 'Vaccines', 'group' => 'Health reference data', 'description' => 'Farm vaccine stock and configuration records.'],
            ['value' => 'medicineTypes', 'label' => 'Medicine Types', 'group' => 'Health reference data', 'description' => 'Configured medicine classifications.'],
            ['value' => 'auditLogs', 'label' => 'Audit Logs', 'group' => 'System & compliance', 'description' => 'Administrative changes and affected record types.'],
        ];
    }

    private function additionalReports(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $identifiers = Schema::hasTable('subject_identifiers') ? DB::table('subject_identifiers as identifier')
            ->leftJoin('animal_subjects as subject', 'subject.uuid', '=', 'identifier.subject_uuid')
            ->whereNull('identifier.deleted_at')->whereIn('subject.farm_uuid', $this->farmIds($filters))->orderByDesc('identifier.assigned_at')->limit(1000)
            ->get([DB::raw('coalesce(subject.display_name, identifier.subject_uuid) as subject'), 'identifier.display_value as identifier', 'identifier.identifier_type_uuid as identifierType', 'identifier.status', 'identifier.assigned_at as assignedAt']) : collect();
        $eventDefinitions = Schema::hasTable('event_definitions') ? DB::table('event_definitions')->orderBy('sort_order')->limit(1000)->get(['code', 'name', 'family', 'behavior_code as behavior', 'status']) : collect();
        $regions = Schema::hasTable('regions') ? DB::table('regions as item')->leftJoin('countries as parent', 'parent.id', '=', 'item.countryId')->orderBy('item.name')->limit(1000)->get(['item.name', 'item.shortName', 'parent.name as parent', 'item.created_at as createdAt']) : collect();
        $districts = Schema::hasTable('districts') ? DB::table('districts as item')->leftJoin('regions as parent', 'parent.id', '=', 'item.regionId')->orderBy('item.name')->limit(1000)->get(['item.name', 'parent.name as parent', 'item.created_at as createdAt']) : collect();
        $wards = Schema::hasTable('wards') ? DB::table('wards as item')->leftJoin('districts as parent', 'parent.id', '=', 'item.districtId')->orderBy('item.name')->limit(1000)->get(['item.name', 'parent.name as parent', 'item.created_at as createdAt']) : collect();
        $villages = Schema::hasTable('villages') ? DB::table('villages as item')->leftJoin('wards as parent', 'parent.id', '=', 'item.wardId')->orderBy('item.name')->limit(1000)->get(['item.name', 'parent.name as parent', 'item.created_at as createdAt']) : collect();
        $species = Schema::hasTable('species') ? DB::table('species as item')->leftJoin('livestock_types as parent', 'parent.id', '=', 'item.livestockTypeId')->orderBy('item.name')->get(['item.name', 'parent.name as parent', 'item.created_at as createdAt']) : collect();
        $breeds = Schema::hasTable('breeds') ? DB::table('breeds as item')->leftJoin('livestock_types as parent', 'parent.id', '=', 'item.livestockTypeId')->orderBy('item.name')->get(['item.name', 'item.group', 'parent.name as parent', 'item.created_at as createdAt']) : collect();
        $vaccines = Schema::hasTable('vaccines') ? DB::table('vaccines as item')->leftJoin('farms as farm', 'farm.uuid', '=', 'item.farmUuid')->whereIn('item.farmUuid', $this->farmIds($filters))->orderByDesc('item.created_at')->limit(1000)->get(['item.name', 'farm.name as farmName', 'item.lot', 'item.formulationType', 'item.dose', 'item.status', 'item.created_at as createdAt']) : collect();
        $medicineTypes = Schema::hasTable('medicine_types') ? DB::table('medicine_types')->orderBy('name')->get(['name', 'created_at as createdAt']) : collect();
        $auditLogs = Schema::hasTable('audit_logs') ? DB::table('audit_logs')->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->limit(1000)->get(['action', 'entity_type as entityType', 'actor_uuid as actor', 'reason', 'ip_address as ipAddress', 'created_at as createdAt']) : collect();

        return [
            'identifiers' => ['columns' => ['subject', 'identifier', 'identifierType', 'status', 'assignedAt'], 'rows' => $identifiers->all()],
            'eventDefinitions' => ['columns' => ['code', 'name', 'family', 'behavior', 'status'], 'rows' => $eventDefinitions->all()],
            'regions' => ['columns' => ['name', 'shortName', 'parent', 'createdAt'], 'rows' => $regions->all()],
            'districts' => ['columns' => ['name', 'parent', 'createdAt'], 'rows' => $districts->all()],
            'wards' => ['columns' => ['name', 'parent', 'createdAt'], 'rows' => $wards->all()],
            'villages' => ['columns' => ['name', 'parent', 'createdAt'], 'rows' => $villages->all()],
            'species' => ['columns' => ['name', 'parent', 'createdAt'], 'rows' => $species->all()],
            'breeds' => ['columns' => ['name', 'group', 'parent', 'createdAt'], 'rows' => $breeds->all()],
            'vaccines' => ['columns' => ['name', 'farmName', 'lot', 'formulationType', 'dose', 'status', 'createdAt'], 'rows' => $vaccines->all()],
            'medicineTypes' => ['columns' => ['name', 'createdAt'], 'rows' => $medicineTypes->all()],
            'auditLogs' => ['columns' => ['action', 'entityType', 'actor', 'reason', 'ipAddress', 'createdAt'], 'rows' => $auditLogs->all()],
        ];
    }

    private function regionRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        $animals = $this->animalByFarmSubquery();
        $events = $this->eventByFarmSubquery($from, $to);

        return $this->farmScope($filters)
            ->leftJoin('countries as c', 'c.id', '=', 'f.countryId')
            ->leftJoin('regions as r', 'r.id', '=', 'f.regionId')
            ->leftJoinSub($animals, 'a', 'a.farm_uuid', '=', 'f.uuid')
            ->leftJoinSub($events, 'e', 'e.farm_uuid', '=', 'f.uuid')
            ->groupBy('c.id', 'c.name', 'r.id', 'r.name')
            ->orderByDesc(DB::raw('coalesce(sum(a.animals), 0)'))
            ->get([
                DB::raw("coalesce(c.name, 'Unassigned') as country"), DB::raw("coalesce(r.name, 'Unassigned') as region"),
                DB::raw('count(distinct f.uuid) as farms'), DB::raw('coalesce(sum(a.animals), 0) as animals'), DB::raw('coalesce(sum(e.events), 0) as events'),
            ])->map(fn (object $row): object => (object) ['country' => $row->country, 'region' => $row->region, 'farms' => (int) $row->farms, 'animals' => (int) $row->animals, 'events' => (int) $row->events]);
    }

    private function countryRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        return $this->regionRows($from, $to, $filters)->groupBy('country')->map(function (Collection $rows, string $country): object {
            return (object) [
                'country' => $country,
                'regions' => $rows->pluck('region')->filter(fn (string $name) => $name !== 'Unassigned')->unique()->count(),
                'farms' => (int) $rows->sum('farms'),
                'animals' => (int) $rows->sum('animals'),
                'events' => (int) $rows->sum('events'),
            ];
        })->sortByDesc('animals')->values();
    }

    private function farmRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        return $this->farmScope($filters)
            ->leftJoin('countries as c', 'c.id', '=', 'f.countryId')
            ->leftJoin('regions as r', 'r.id', '=', 'f.regionId')
            ->leftJoin('farmers as owner', 'owner.id', '=', 'f.farmerId')
            ->leftJoinSub($this->animalByFarmSubquery(), 'a', 'a.farm_uuid', '=', 'f.uuid')
            ->leftJoinSub($this->eventByFarmSubquery($from, $to), 'e', 'e.farm_uuid', '=', 'f.uuid')
            ->orderByDesc(DB::raw('coalesce(e.events, 0)'))
            ->limit(500)
            ->get([
                'f.uuid as farmUuid', 'f.referenceNo', 'f.name as farmName', 'f.size', 'f.sizeUnit', 'f.status',
                'owner.firstName', 'owner.middleName', 'owner.surname',
                DB::raw("coalesce(c.name, 'Unassigned') as country"), DB::raw("coalesce(r.name, 'Unassigned') as region"),
                DB::raw('coalesce(a.animals, 0) as animals'), DB::raw('coalesce(e.events, 0) as events'), 'e.last_activity as lastActivity',
            ])->map(fn (object $row): object => (object) ['farmUuid' => $row->farmUuid, 'referenceNo' => $row->referenceNo, 'farmName' => $row->farmName, 'ownerName' => trim(implode(' ', array_filter([$row->firstName, $row->middleName, $row->surname]))) ?: 'Unassigned', 'size' => $row->size, 'sizeUnit' => $row->sizeUnit, 'country' => $row->country, 'region' => $row->region, 'status' => $row->status, 'animals' => (int) $row->animals, 'events' => (int) $row->events, 'lastActivity' => $row->lastActivity]);
    }

    private function farmerRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        return $this->farmScope($filters)
            ->join('farmers as owner', 'owner.id', '=', 'f.farmerId')
            ->leftJoinSub($this->animalByFarmSubquery(), 'a', 'a.farm_uuid', '=', 'f.uuid')
            ->leftJoinSub($this->eventByFarmSubquery($from, $to), 'e', 'e.farm_uuid', '=', 'f.uuid')
            ->groupBy('owner.id', 'owner.farmerNo', 'owner.firstName', 'owner.middleName', 'owner.surname', 'owner.phone1', 'owner.status')
            ->orderBy('owner.firstName')
            ->get([
                'owner.farmerNo', 'owner.firstName', 'owner.middleName', 'owner.surname', 'owner.phone1 as phone', 'owner.status',
                DB::raw('count(distinct f.uuid) as farms'), DB::raw('coalesce(sum(a.animals), 0) as animals'),
                DB::raw('coalesce(sum(e.events), 0) as events'), DB::raw('max(e.last_activity) as lastActivity'),
            ])->map(fn (object $row): object => (object) [
                'farmerNo' => $row->farmerNo,
                'farmerName' => trim(implode(' ', array_filter([$row->firstName, $row->middleName, $row->surname]))),
                'phone' => $row->phone,
                'status' => $row->status,
                'farms' => (int) $row->farms,
                'animals' => (int) $row->animals,
                'events' => (int) $row->events,
                'lastActivity' => $row->lastActivity,
            ]);
    }

    private function financeRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        $income = Schema::hasTable('finance_incomes')
            ? DB::table('finance_incomes as item')
                ->leftJoin('farms as f', 'f.uuid', '=', 'item.farmUuid')
                ->whereIn('item.farmUuid', $this->farmIds($filters))
                ->whereBetween(DB::raw('coalesce(item.incomeDate, item.created_at)'), [$from, $to])
                ->get([
                    DB::raw("'Income' as transactionType"), 'item.referenceNo as reference', DB::raw("coalesce(f.name, 'Unassigned') as farmName"),
                    'item.sourceType as source', 'item.totalAmount as amount', 'item.status', DB::raw('coalesce(item.incomeDate, item.created_at) as transactionDate'),
                ])
            : collect();
        $expenses = Schema::hasTable('finance_expenses')
            ? DB::table('finance_expenses as item')
                ->leftJoin('farms as f', 'f.uuid', '=', 'item.farmUuid')
                ->whereIn('item.farmUuid', $this->farmIds($filters))
                ->whereBetween(DB::raw('coalesce(item.expenseDate, item.created_at)'), [$from, $to])
                ->get([
                    DB::raw("'Expense' as transactionType"), 'item.billNo as reference', DB::raw("coalesce(f.name, 'Unassigned') as farmName"),
                    'item.sourceType as source', 'item.totalCost as amount', 'item.status', DB::raw('coalesce(item.expenseDate, item.created_at) as transactionDate'),
                ])
            : collect();

        return $income->concat($expenses)->map(function (object $row): object {
            $row->amount = (float) $row->amount;

            return $row;
        })->sortByDesc('transactionDate')->take(1000)->values();
    }

    private function farmUserRows(): Collection
    {
        if (! Schema::hasTable('farm_users')) {
            return collect();
        }

        return DB::table('farm_users as member')
            ->orderByDesc('member.created_at')
            ->limit(1000)
            ->get([
                'member.firstName', 'member.middleName', 'member.lastName', 'member.phone', 'member.email',
                'member.roleTitle', 'member.gender', 'member.status', 'member.created_at as createdAt',
            ])->map(function (object $row): object {
                $row->fullName = trim(implode(' ', array_filter([$row->firstName, $row->middleName, $row->lastName])));
                unset($row->firstName, $row->middleName, $row->lastName);

                return $row;
            });
    }

    private function extensionOfficerRows(): Collection
    {
        if (! Schema::hasTable('extension_officers')) {
            return collect();
        }

        $invites = Schema::hasTable('extension_officer_farm_invites')
            ? DB::table('extension_officer_farm_invites')->select('extensionOfficerId')->selectRaw('count(*) as invites')->groupBy('extensionOfficerId')
            : null;
        $query = DB::table('extension_officers as officer');
        if ($invites) {
            $query->leftJoinSub($invites, 'invite_count', 'invite_count.extensionOfficerId', '=', 'officer.id');
        }

        return $query->orderByDesc('officer.created_at')->limit(1000)->get([
            'officer.firstName', 'officer.middleName', 'officer.lastName', 'officer.phone', 'officer.email',
            'officer.organization', 'officer.specialization', 'officer.isVerified as verified',
            DB::raw($invites ? 'coalesce(invite_count.invites, 0) as invites' : '0 as invites'),
        ])->map(function (object $row): object {
            $row->fullName = trim(implode(' ', array_filter([$row->firstName, $row->middleName, $row->lastName])));
            $row->verified = (bool) $row->verified ? 'Yes' : 'No';
            $row->invites = (int) $row->invites;
            unset($row->firstName, $row->middleName, $row->lastName);

            return $row;
        });
    }

    private function farmInviteRows(): Collection
    {
        if (! Schema::hasTable('extension_officer_farm_invites')) {
            return collect();
        }

        return DB::table('extension_officer_farm_invites as invite')
            ->leftJoin('extension_officers as officer', 'officer.id', '=', 'invite.extensionOfficerId')
            ->leftJoin('farmers as owner', 'owner.id', '=', 'invite.farmerId')
            ->orderByDesc('invite.created_at')->limit(1000)
            ->get([
                'officer.firstName as officerFirstName', 'officer.middleName as officerMiddleName', 'officer.lastName as officerLastName',
                'owner.firstName as farmerFirstName', 'owner.middleName as farmerMiddleName', 'owner.surname as farmerSurname',
                'owner.email', 'invite.access_code as accessCode', 'invite.created_at as createdAt',
            ])->map(function (object $row): object {
                $row->extensionOfficer = trim(implode(' ', array_filter([$row->officerFirstName, $row->officerMiddleName, $row->officerLastName]))) ?: 'Unassigned';
                $row->farmerName = trim(implode(' ', array_filter([$row->farmerFirstName, $row->farmerMiddleName, $row->farmerSurname]))) ?: 'Unassigned';
                unset($row->officerFirstName, $row->officerMiddleName, $row->officerLastName, $row->farmerFirstName, $row->farmerMiddleName, $row->farmerSurname);

                return $row;
            });
    }

    private function userAccountRows(): Collection
    {
        if (! Schema::hasTable('users')) {
            return collect();
        }

        return DB::table('users')->orderByDesc('created_at')->limit(1000)->get([
            'username', 'email', 'role', 'status as accountStatus', 'created_at as createdAt',
        ]);
    }

    private function systemUserRows(): Collection
    {
        if (! Schema::hasTable('system_users')) {
            return collect();
        }

        return DB::table('system_users as administrator')->orderByDesc('administrator.created_at')->limit(1000)
            ->get(['administrator.firstName', 'administrator.middleName', 'administrator.lastName', 'administrator.phone', 'administrator.address', 'administrator.status', 'administrator.created_at as createdAt'])
            ->map(function (object $row): object {
                $row->fullName = trim(implode(' ', array_filter([$row->firstName, $row->middleName, $row->lastName])));
                unset($row->firstName, $row->middleName, $row->lastName);

                return $row;
            });
    }

    private function billRows(CarbonImmutable $from, CarbonImmutable $to, array $filters): Collection
    {
        if (! Schema::hasTable('bills')) {
            return collect();
        }

        return DB::table('bills as bill')
            ->leftJoin('farms as f', 'f.uuid', '=', 'bill.farmUuid')
            ->leftJoin('farmers as owner', 'owner.id', '=', 'bill.farmerId')
            ->whereIn('bill.farmUuid', $this->farmIds($filters))
            ->whereBetween('bill.created_at', [$from, $to])
            ->orderByDesc('bill.created_at')
            ->limit(1000)
            ->get([
                'bill.billNo', DB::raw("coalesce(f.name, 'Unassigned') as farmName"),
                'owner.firstName', 'owner.surname',
                'bill.subjectType', 'bill.quantity', 'bill.amount', 'bill.status', 'bill.created_at as billDate',
            ])->map(function (object $row): object {
                $row->farmerName = trim(implode(' ', array_filter([$row->firstName, $row->surname])));
                unset($row->firstName, $row->surname);
                $row->quantity = (int) $row->quantity;
                $row->amount = (float) $row->amount;

                return $row;
            });
    }

    private function attention(array $filters): array
    {
        $untagged = Schema::hasTable('subject_identifiers')
            ? DB::table('animal_subjects as s')->whereNull('s.deleted_at')->whereIn('s.farm_uuid', $this->farmIds($filters))->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('subject_identifiers as i')->whereColumn('i.subject_uuid', 's.uuid')->where('i.status', 'active')->whereNull('i.deleted_at');
            })->count()
            : 0;
        $inactiveFarms = $this->farmScope($filters)->where('f.status', '!=', 'active')->count();
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $syncConflicts = Schema::hasTable('sync_conflicts') ? DB::table('sync_conflicts')->where('status', 'open')->count() : 0;
        $cutoff = now()->subDays(30);
        $inactiveActivity = $this->farmScope($filters)->whereNotExists(function (Builder $query) use ($cutoff): void {
            $query->selectRaw('1')->from('event_records as e')->whereColumn('e.farm_uuid', 'f.uuid')->where('e.record_status', '!=', 'voided')->where('e.occurred_at', '>=', $cutoff);
        })->count();

        return [
            ['key' => 'untagged', 'severity' => $untagged > 0 ? 'warning' : 'success', 'value' => $untagged, 'title' => 'Animals without active identifiers', 'description' => 'Registration records needing identification review.', 'href' => '/dashboard/animals/animals'],
            ['key' => 'farm-activity', 'severity' => $inactiveActivity > 0 ? 'warning' : 'success', 'value' => $inactiveActivity, 'title' => 'Farms without recent activity', 'description' => 'No ledger events recorded in the last 30 days.', 'href' => '/dashboard/people/farms'],
            ['key' => 'inactive-farms', 'severity' => $inactiveFarms > 0 ? 'info' : 'success', 'value' => $inactiveFarms, 'title' => 'Inactive farm records', 'description' => 'Farm status is not currently active.', 'href' => '/dashboard/people/farms'],
            ['key' => 'sync-conflicts', 'severity' => $syncConflicts > 0 ? 'danger' : 'success', 'value' => $syncConflicts, 'title' => 'Open synchronization conflicts', 'description' => 'Device changes requiring backend reconciliation.', 'href' => '/dashboard/system/ledger-migration'],
            ['key' => 'failed-jobs', 'severity' => $failedJobs > 0 ? 'danger' : 'success', 'value' => $failedJobs, 'title' => 'Failed background jobs', 'description' => 'Queued operations that need administrator attention.', 'href' => '/dashboard/system/upgrade-runs'],
        ];
    }

    private function filterOptions(): array
    {
        return [
            'countries' => DB::table('countries')->orderBy('name')->get(['id', 'name'])->map(fn (object $row): array => ['value' => (string) $row->id, 'label' => $row->name])->all(),
            'regions' => DB::table('regions')->orderBy('name')->get(['id', 'countryId', 'name'])->map(fn (object $row): array => ['value' => (string) $row->id, 'label' => $row->name, 'parent' => (string) $row->countryId])->all(),
            'farms' => DB::table('farms')->orderBy('name')->limit(1000)->get(['uuid', 'countryId', 'regionId', 'name', 'referenceNo'])->map(fn (object $row): array => ['value' => $row->uuid, 'label' => $row->name, 'description' => $row->referenceNo, 'countryId' => (string) $row->countryId, 'regionId' => (string) $row->regionId])->all(),
        ];
    }

    private function eventScope(CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        return DB::table('event_records as e')->where('e.record_status', '!=', 'voided')->whereIn('e.farm_uuid', $this->farmIds($filters))->whereBetween('e.occurred_at', [$from, $to]);
    }

    private function farmScope(array $filters): Builder
    {
        return DB::table('farms as f')
            ->when($filters['countryId'], fn (Builder $query, int $id) => $query->where('f.countryId', $id))
            ->when($filters['regionId'], fn (Builder $query, int $id) => $query->where('f.regionId', $id))
            ->when($filters['farmUuid'], fn (Builder $query, string $uuid) => $query->where('f.uuid', $uuid));
    }

    private function farmIds(array $filters): Builder
    {
        return $this->farmScope($filters)->select('f.uuid');
    }

    private function animalByFarmSubquery(): Builder
    {
        return DB::table('animal_subjects as s')->whereNull('s.deleted_at')->groupBy('s.farm_uuid')->select('s.farm_uuid')->selectRaw("sum(case when s.unit_type = 'group' then coalesce(s.quantity, 0) else 1 end) as animals");
    }

    private function eventByFarmSubquery(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('event_records as er')->where('er.record_status', '!=', 'voided')->whereBetween('er.occurred_at', [$from, $to])->groupBy('er.farm_uuid')->select('er.farm_uuid')->selectRaw('count(*) as events')->selectRaw('max(er.occurred_at) as last_activity');
    }

    private function eventCount(CarbonImmutable $from, CarbonImmutable $to, array $filters): int
    {
        return (int) $this->eventScope($from, $to, $filters)->count();
    }

    private function animalRegistrationCount(CarbonImmutable $from, CarbonImmutable $to, array $filters): int
    {
        return (int) DB::table('animal_subjects as s')->whereNull('s.deleted_at')->whereIn('s.farm_uuid', $this->farmIds($filters))->whereBetween('s.created_at', [$from, $to])->selectRaw("coalesce(sum(case when s.unit_type = 'group' then coalesce(s.quantity, 0) else 1 end), 0) as total")->value('total');
    }

    private function productionQuantity(CarbonImmutable $from, CarbonImmutable $to, array $filters, string $product): float
    {
        if (! Schema::hasTable('subject_production_daily')) {
            return 0;
        }

        return (float) DB::table('subject_production_daily as p')->whereIn('p.farm_uuid', $this->farmIds($filters))->whereBetween('p.production_date', [$from->toDateString(), $to->toDateString()])->where('p.product_code', 'like', "%{$product}%")->sum('p.quantity');
    }

    private function familyEventCount(CarbonImmutable $from, CarbonImmutable $to, array $filters, string $family): int
    {
        $query = $this->eventScope($from, $to, $filters);
        if ($family === 'birth') {
            return (int) $query->where(fn (Builder $scope) => $scope->whereIn('e.behavior_code', ['birth', 'record_birth'])->orWhere('e.event_code', 'like', '%birth%')->orWhere('e.event_code', 'like', '%calving%')->orWhere('e.event_code', 'like', '%farrowing%')->orWhere('e.event_code', 'like', '%hatching%'))->count();
        }

        return (int) $query->where(fn (Builder $scope) => $scope->whereIn('e.behavior_code', ['vaccination', 'deworming', 'medication', 'condition_record'])->orWhere('e.event_code', 'like', '%health%')->orWhere('e.event_code', 'like', '%vaccin%')->orWhere('e.event_code', 'like', '%treat%')->orWhere('e.event_code', 'like', '%disease%'))->count();
    }

    private function stat(string $key, string $label, int|float $value, int|float|null $previous, string $note, ?string $unit = null): array
    {
        $change = $previous === null ? null : ($previous > 0 ? round((($value - $previous) / $previous) * 100, 1) : ($value > 0 ? 100.0 : 0.0));

        return ['key' => $key, 'label' => $label, 'value' => $value, 'previous' => $previous, 'change' => $change, 'direction' => $change === null || $change === 0.0 ? 'neutral' : ($change > 0 ? 'up' : 'down'), 'note' => $note, 'unit' => $unit];
    }

    private function periodExpression(string $column, bool $daily): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return $daily ? "strftime('%Y-%m-%d', {$column})" : "strftime('%Y-%m', {$column})";
        }

        return $daily ? "date_format({$column}, '%Y-%m-%d')" : "date_format({$column}, '%Y-%m')";
    }

    private function periods(CarbonImmutable $from, CarbonImmutable $to, bool $daily): Collection
    {
        $periods = collect();
        $cursor = $daily ? $from->startOfDay() : $from->startOfMonth();
        $end = $daily ? $to->startOfDay() : $to->startOfMonth();
        while ($cursor <= $end) {
            $periods->push(['key' => $cursor->format($daily ? 'Y-m-d' : 'Y-m'), 'label' => $cursor->format($daily ? 'd M' : 'M Y')]);
            $cursor = $daily ? $cursor->addDay() : $cursor->addMonth();
        }

        return $periods;
    }

    private function family(string $family, string $eventCode = ''): string
    {
        $value = strtolower($family.' '.$eventCode);

        return match (true) {
            str_contains($value, 'health'), str_contains($value, 'vaccin'), str_contains($value, 'treat'), str_contains($value, 'deworm'), str_contains($value, 'medicat'), str_contains($value, 'disease') => 'health',
            str_contains($value, 'production'), str_contains($value, 'milk'), str_contains($value, 'egg') => 'production',
            str_contains($value, 'reproduction'), str_contains($value, 'breeding'), str_contains($value, 'birth'), str_contains($value, 'pregnan'), str_contains($value, 'insemin') => 'reproduction',
            str_contains($value, 'movement'), str_contains($value, 'transfer'), str_contains($value, 'disposal') => 'movement',
            str_contains($value, 'growth'), str_contains($value, 'weight'), str_contains($value, 'feeding'), str_contains($value, 'stage') => 'growth',
            default => 'other',
        };
    }
}
