<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdministrationRoute;
use App\Models\AnimalSubject;
use App\Models\AuditLog;
use App\Models\BirthProblem;
use App\Models\BirthType;
use App\Models\Disease;
use App\Models\DisposalType;
use App\Models\EventDefinition;
use App\Models\EventRecord;
use App\Models\ExtensionOfficer;
use App\Models\Farm;
use App\Models\FeedingType;
use App\Models\HeatType;
use App\Models\IdentificationEvent;
use App\Models\InseminationService;
use App\Models\Livestock;
use App\Models\LivestockMarkingType;
use App\Models\LookupOption;
use App\Models\Medicine;
use App\Models\MilkingMethod;
use App\Models\PregnancyTestResult;
use App\Models\PrepuceBreedingStatus;
use App\Models\PrepuceCauseRisk;
use App\Models\PrepuceConditionType;
use App\Models\PrepuceHealingStatus;
use App\Models\PrepuceSeverity;
use App\Models\ReproductiveProblem;
use App\Models\SemenStrawType;
use App\Models\Stage;
use App\Models\TailDockingMethod;
use App\Models\TeethClippingMethod;
use App\Models\Vaccine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventResourceController extends Controller
{
    /**
     * Map of event resource slugs to their event_code, behavior_code, family, and display name.
     *
     * @return array<string, array{code: ?string, behavior: ?string, family: ?string, label: string}>
     */
    public static function catalog(): array
    {
        return [
            'all-events' => ['code' => null, 'behavior' => null, 'family' => null, 'label' => 'All Events'],
            'calving' => ['code' => 'record_calving', 'behavior' => 'birth', 'family' => 'births', 'label' => 'Calving'],
            'farrowing' => ['code' => 'record_farrowing', 'behavior' => 'birth', 'family' => 'births', 'label' => 'Farrowing'],
            'aborted-pregnancies' => ['code' => 'record_abortion', 'behavior' => 'abortion', 'family' => 'births', 'label' => 'Aborted Pregnancies'],
            'vaccinations' => ['code' => 'record_vaccination', 'behavior' => 'vaccination', 'family' => 'health', 'label' => 'Vaccinations'],
            'dewormings' => ['code' => 'record_deworming', 'behavior' => 'deworming', 'family' => 'health', 'label' => 'Dewormings'],
            'treatments' => ['code' => 'record_medication', 'behavior' => 'medication', 'family' => 'health', 'label' => 'Treatments'],
            'prepuce-conditions' => ['code' => 'record_prepuce_condition', 'behavior' => 'condition_record', 'family' => 'health', 'label' => 'Prepuce Conditions'],
            'milkings' => ['code' => 'record_milking', 'behavior' => 'milking', 'family' => 'production', 'label' => 'Milkings'],
            'egg-collections' => ['code' => 'record_egg_collection', 'behavior' => 'production_yield', 'family' => 'production', 'label' => 'Egg Collections'],
            'inseminations' => ['code' => 'record_insemination', 'behavior' => 'insemination', 'family' => 'breeding', 'label' => 'Inseminations'],
            'pregnancy-tests' => ['code' => 'record_pregnancy_test', 'behavior' => 'pregnancy_test', 'family' => 'breeding', 'label' => 'Pregnancy Tests'],
            'dryoffs' => ['code' => 'record_dryoff', 'behavior' => 'dry_off', 'family' => 'breeding', 'label' => 'Dry-offs'],
            'weight-changes' => ['code' => 'record_weight', 'behavior' => 'weight_measurement', 'family' => 'growth', 'label' => 'Weight Changes'],
            'feedings' => ['code' => 'record_feeding', 'behavior' => 'feeding', 'family' => 'growth', 'label' => 'Feedings'],
            'iron-injections' => ['code' => 'record_iron_injection', 'behavior' => 'husbandry_procedure', 'family' => 'growth', 'label' => 'Iron Injections'],
            'teeth-clippings' => ['code' => 'record_teeth_clipping', 'behavior' => 'husbandry_procedure', 'family' => 'growth', 'label' => 'Teeth Clippings'],
            'tail-dockings' => ['code' => 'record_tail_docking', 'behavior' => 'husbandry_procedure', 'family' => 'growth', 'label' => 'Tail Dockings'],
            'markings' => ['code' => 'record_marking', 'behavior' => 'marking', 'family' => 'growth', 'label' => 'Livestock Markings'],
            'stage-changes' => ['code' => 'record_stage_change', 'behavior' => 'stage_change', 'family' => 'growth', 'label' => 'Stage Changes'],
            'transfers' => ['code' => 'record_transfer', 'behavior' => 'transfer', 'family' => 'movement', 'label' => 'Transfers'],
            'disposals' => ['code' => 'record_disposal', 'behavior' => 'disposal', 'family' => 'movement', 'label' => 'Disposals'],
            'identification-events' => ['code' => 'identification_events', 'behavior' => 'identification', 'family' => 'identity', 'label' => 'Identification Events'],
        ];
    }

    /** @var array<string, string>|null */
    private ?array $lookupLabelsCache = null;

    /** @var array<string, string>|null */
    private ?array $farmNamesCache = null;

    public function index(Request $request, string $resource): JsonResponse
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$resource]), 404);

        if ($resource === 'identification-events') {
            return $this->indexIdentificationEvents($request);
        }

        $metaConfig = $catalog[$resource];
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $tab = (string) $request->query('tab', 'all');
        $sort = (string) $request->query('sort', 'latest');
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);

        $baseQuery = EventRecord::query();
        if ($metaConfig['code'] !== null) {
            $baseQuery->where('event_code', $metaConfig['code']);
        }

        $totalActive = (clone $baseQuery)->where('record_status', '!=', 'voided')->count();
        $deletedCount = (clone $baseQuery)->where('record_status', 'voided')->count();
        $confirmedCount = (clone $baseQuery)->where('record_status', 'confirmed')->count();
        $recentCount = (clone $baseQuery)
            ->where('record_status', '!=', 'voided')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->count();

        $query = EventRecord::query()
            ->with(['subject.species', 'subject.identifiers', 'farm.farmer', 'definition', 'behavior']);

        if ($metaConfig['code'] !== null) {
            $query->where('event_code', $metaConfig['code']);
        }

        if ($tab === 'deleted') {
            $query->where('record_status', 'voided');
        } else {
            $query->where('record_status', '!=', 'voided');
        }

        if ($status !== '' && $status !== 'all') {
            $query->where('record_status', $status);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('event_code', 'like', "%{$search}%")
                    ->orWhere('behavior_code', 'like', "%{$search}%")
                    ->orWhere('payload_json', 'like', "%{$search}%")
                    ->orWhereHas('subject', fn ($q) => $q->where('display_name', 'like', "%{$search}%")->orWhereHas('identifiers', fn ($iq) => $iq->where('display_value', 'like', "%{$search}%")))
                    ->orWhereHas('farm', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('referenceNo', 'like', "%{$search}%"));
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('occurred_at')->orderBy('id'),
            default => $query->orderByDesc('occurred_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Event records retrieved successfully.',
            'data' => [
                'items' => collect($paginator->items())->map(fn (EventRecord $record) => $this->presentEventRecord($record))->values(),
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'deletedCount' => $deletedCount,
                ],
                'summary' => [
                    'total' => $totalActive,
                    'active' => $confirmedCount,
                    'secondary' => $recentCount,
                    'tertiary' => $deletedCount,
                ],
                'options' => $this->optionsPayload($resource),
            ],
        ]);
    }

    public function options(string $resource): JsonResponse
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$resource]), 404);

        return response()->json([
            'status' => true,
            'message' => 'Event options retrieved successfully.',
            'data' => $this->optionsPayload($resource),
        ]);
    }

    public function show(string $resource, string $record): JsonResponse
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$resource]), 404);

        if ($resource === 'identification-events') {
            $item = IdentificationEvent::with(['farm.farmer', 'livestock'])->findOrFail($record);

            return response()->json([
                'status' => true,
                'message' => 'Identification event retrieved successfully.',
                'data' => $this->presentIdentificationEvent($item),
            ]);
        }

        $item = EventRecord::with(['subject.species', 'subject.identifiers', 'farm.farmer', 'definition', 'behavior'])->findOrFail($record);

        return response()->json([
            'status' => true,
            'message' => 'Event record retrieved successfully.',
            'data' => $this->presentEventRecord($item),
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$resource]), 404);

        if ($resource === 'identification-events') {
            $data = $request->validate([
                'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
                'livestockUuid' => ['nullable', 'string'],
                'identificationNumber' => ['required', 'string', 'max:255'],
                'eventDate' => ['nullable', 'date'],
                'remarks' => ['nullable', 'string'],
            ]);

            $created = IdentificationEvent::create([
                'uuid' => (string) Str::uuid(),
                'farmUuid' => $data['farmUuid'],
                'livestockUuid' => ! empty($data['livestockUuid']) ? $data['livestockUuid'] : (string) Str::uuid(),
                'identificationNumber' => $data['identificationNumber'],
                'eventDate' => $data['eventDate'] ?? now(),
                'remarks' => $data['remarks'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Identification event created successfully.',
                'data' => $this->presentIdentificationEvent($created->load(['farm.farmer', 'livestock'])),
            ], 201);
        }

        $metaConfig = $catalog[$resource];
        $validated = $request->validate([
            'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'subjectUuid' => ['required', 'string', 'exists:animal_subjects,uuid'],
            'eventCode' => ['nullable', 'string', 'max:120'],
            'occurredAt' => ['nullable', 'date'],
            'recordStatus' => ['nullable', 'string', 'in:confirmed,submitted,voided'],
            'payload' => ['nullable', 'array'],
        ]);

        $eventCode = $metaConfig['code'] ?? ($validated['eventCode'] ?? 'record_weight');
        $definition = EventDefinition::where('code', $eventCode)->first();
        $behaviorCode = $definition?->behavior_code ?? $metaConfig['behavior'] ?? $this->inferBehaviorCode($eventCode);

        $payload = $this->extractPayloadFromRequest($request, $validated['payload'] ?? []);

        $subject = AnimalSubject::where('uuid', $validated['subjectUuid'])->first();
        $farmUuid = $validated['farmUuid'] ?: ($subject?->farm_uuid ?? '');

        $created = EventRecord::create([
            'uuid' => (string) Str::uuid(),
            'farm_uuid' => $farmUuid,
            'subject_uuid' => $validated['subjectUuid'],
            'event_definition_uuid' => $definition?->uuid ?? (string) Str::uuid(),
            'event_code' => $eventCode,
            'behavior_code' => $behaviorCode,
            'event_definition_version' => 1,
            'form_uuid' => $definition?->form_uuid ?? (string) Str::uuid(),
            'form_version' => $definition?->form_version ?? 1,
            'occurred_at' => $validated['occurredAt'] ?? now(),
            'payload_json' => $payload,
            'record_status' => $validated['recordStatus'] ?? 'confirmed',
            'created_by_uuid' => null,
            'idempotency_uuid' => (string) Str::uuid(),
            'revision' => 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Event record created successfully.',
            'data' => $this->presentEventRecord($created->load(['subject.species', 'subject.identifiers', 'farm.farmer', 'definition', 'behavior'])),
        ], 201);
    }

    public function update(Request $request, string $resource, string $record): JsonResponse
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$resource]), 404);

        if ($resource === 'identification-events') {
            $item = IdentificationEvent::findOrFail($record);
            $data = $request->validate([
                'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
                'livestockUuid' => ['nullable', 'string'],
                'identificationNumber' => ['required', 'string', 'max:255'],
                'eventDate' => ['nullable', 'date'],
                'remarks' => ['nullable', 'string'],
            ]);

            $item->update([
                'farmUuid' => $data['farmUuid'],
                'livestockUuid' => $data['livestockUuid'] ?? $item->livestockUuid,
                'identificationNumber' => $data['identificationNumber'],
                'eventDate' => $data['eventDate'] ?? $item->eventDate,
                'remarks' => $data['remarks'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Identification event updated successfully.',
                'data' => $this->presentIdentificationEvent($item->fresh()->load(['farm.farmer', 'livestock'])),
            ]);
        }

        $item = EventRecord::findOrFail($record);
        $validated = $request->validate([
            'farmUuid' => ['nullable', 'string', 'exists:farms,uuid'],
            'subjectUuid' => ['nullable', 'string', 'exists:animal_subjects,uuid'],
            'eventCode' => ['nullable', 'string', 'max:120'],
            'occurredAt' => ['nullable', 'date'],
            'recordStatus' => ['nullable', 'string', 'in:confirmed,submitted,voided'],
            'payload' => ['nullable', 'array'],
        ]);

        $beforePayload = $item->payload_json ?? [];
        $updatedPayload = array_merge($beforePayload, $this->extractPayloadFromRequest($request, $validated['payload'] ?? []));

        $item->update([
            'farm_uuid' => $validated['farmUuid'] ?? $item->farm_uuid,
            'subject_uuid' => $validated['subjectUuid'] ?? $item->subject_uuid,
            'event_code' => $validated['eventCode'] ?? $item->event_code,
            'occurred_at' => $validated['occurredAt'] ?? $item->occurred_at,
            'record_status' => $validated['recordStatus'] ?? $item->record_status,
            'payload_json' => $updatedPayload,
            'revision' => ((int) $item->revision) + 1,
        ]);

        AuditLog::create([
            'uuid' => (string) Str::uuid(),
            'actor_uuid' => null,
            'action' => 'event.payload_updated',
            'entity_type' => 'event_record',
            'entity_uuid' => $item->uuid,
            'before_json' => ['payload_json' => $beforePayload],
            'after_json' => ['payload_json' => $updatedPayload],
            'reason' => 'Updated by administrator',
            'request_uuid' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Event record updated successfully.',
            'data' => $this->presentEventRecord($item->fresh()->load(['subject.species', 'subject.identifiers', 'farm.farmer', 'definition', 'behavior'])),
        ]);
    }

    public function restore(string $resource, string $record): JsonResponse
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$resource]), 404);
        abort_if($resource === 'identification-events', 404);

        $item = EventRecord::findOrFail($record);
        $item->update([
            'record_status' => 'confirmed',
            'voided_at' => null,
            'voided_by_uuid' => null,
            'void_reason' => null,
            'revision' => ((int) $item->revision) + 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Event record restored successfully.',
            'data' => $this->presentEventRecord($item->fresh()->load(['subject.species', 'subject.identifiers', 'farm.farmer', 'definition', 'behavior'])),
        ]);
    }

    public function destroy(Request $request, string $resource, string $record): JsonResponse
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$resource]), 404);

        if ($resource === 'identification-events') {
            $item = IdentificationEvent::findOrFail($record);
            $item->delete();

            return response()->json([
                'status' => true,
                'message' => 'Identification event deleted successfully.',
            ]);
        }

        $item = EventRecord::findOrFail($record);
        $force = $request->boolean('force') || $item->record_status === 'voided';

        if ($force) {
            $item->delete();

            return response()->json([
                'status' => true,
                'message' => 'Event record permanently deleted.',
            ]);
        }

        $item->update([
            'record_status' => 'voided',
            'voided_at' => now(),
            'void_reason' => 'Archived by administrator',
            'revision' => ((int) $item->revision) + 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Event record moved to deleted tab.',
        ]);
    }

    private function indexIdentificationEvents(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $sort = (string) $request->query('sort', 'latest');
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);

        $total = IdentificationEvent::count();
        $withLivestock = IdentificationEvent::whereNotNull('livestockUuid')->count();
        $recent = IdentificationEvent::where('created_at', '>=', now()->subDays(30))->count();
        $farmsCount = IdentificationEvent::distinct('farmUuid')->count('farmUuid');

        $query = IdentificationEvent::query()->with(['farm.farmer', 'livestock']);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('identificationNumber', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%")
                    ->orWhereHas('farm', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('referenceNo', 'like', "%{$search}%"))
                    ->orWhereHas('livestock', fn ($q) => $q->where('identificationNumber', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('id'),
            default => $query->orderByDesc('eventDate')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Identification events retrieved successfully.',
            'data' => [
                'items' => collect($paginator->items())->map(fn (IdentificationEvent $item) => $this->presentIdentificationEvent($item))->values(),
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'deletedCount' => 0,
                ],
                'summary' => [
                    'total' => $total,
                    'active' => $withLivestock,
                    'secondary' => $recent,
                    'tertiary' => $farmsCount,
                ],
                'options' => $this->optionsPayload('identification-events'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIdentificationEvent(IdentificationEvent $item): array
    {
        $farmerName = $item->farm?->farmer
            ? trim(collect([$item->farm->farmer->firstName, $item->farm->farmer->middleName, $item->farm->farmer->surname])->filter()->implode(' '))
            : null;
        $livestockLabel = $item->livestock
            ? trim(($item->livestock->identificationNumber ?: 'Untagged').($item->livestock->name ? ' · '.$item->livestock->name : ''))
            : '—';

        return [
            'id' => $item->id,
            'farmUuid' => $item->farmUuid,
            'farmName' => $item->farm?->name ?? '—',
            'farmerName' => $farmerName ?: '—',
            'livestockUuid' => $item->livestockUuid,
            'livestockLabel' => $livestockLabel,
            'identificationNumber' => $item->identificationNumber,
            'eventDate' => $item->eventDate?->toISOString(),
            'occurredAt' => $item->eventDate?->toISOString(),
            'remarks' => $item->remarks,
            'recordStatus' => 'confirmed',
            'createdAt' => $item->created_at?->toISOString(),
            'updatedAt' => $item->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEventRecord(EventRecord $record): array
    {
        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $farmerName = $record->farm?->farmer
            ? trim(collect([$record->farm->farmer->firstName, $record->farm->farmer->middleName, $record->farm->farmer->surname])->filter()->implode(' '))
            : null;

        $primaryTag = $record->subject && $record->subject->relationLoaded('identifiers')
            ? $record->subject->identifiers->first()?->display_value
            : null;
        $subjectName = $record->subject?->display_name;
        $subjectIdentifier = $primaryTag ?: ($subjectName ?: '—');
        $subjectLabel = $primaryTag && $subjectName && $subjectName !== $primaryTag
            ? "{$primaryTag} · {$subjectName}"
            : $subjectIdentifier;

        $speciesTranslations = $record->subject?->species?->translations_json;
        $speciesName = $record->subject?->species?->name
            ?: (is_array($speciesTranslations)
                ? ($speciesTranslations['en']['name'] ?? $record->subject?->species?->code ?? '—')
                : ($record->subject?->species?->code ?? '—'));

        $defTranslations = $record->definition?->translations_json;
        $eventName = is_array($defTranslations) && ! empty($defTranslations['en']['name'])
            ? $defTranslations['en']['name']
            : $this->humanizeCode($record->event_code);

        $familyCode = $record->familyCode() ?? $this->inferFamilyCode($record->event_code);

        $pick = function (array $keys) use ($payload) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                    return $payload[$key];
                }
            }

            return null;
        };

        $bornAlive = $pick(['born_alive', 'bornAlive']);
        $bornDead = $pick(['born_dead', 'bornDead']);
        $birthTypeRaw = $pick(['birth_type', 'birthTypeId']);
        $birthProblemRaw = $pick(['birth_problem', 'birthProblemId']);
        $reproductiveProblemRaw = $pick(['reproductive_problem', 'reproductiveProblemId']);
        $labourStartedOn = $pick(['labour_started_on', 'labourStartedOn']);
        $abortionDate = $pick(['abortion_date', 'abortionDate']);

        $vaccinationNo = $pick(['vaccination_no', 'vaccinationNo']);
        $vaccineRaw = $pick(['vaccine', 'vaccineUuid']);
        $diseaseRaw = $pick(['disease', 'diseaseId']);
        $vetRaw = $pick(['vet', 'vetId']);
        $extensionOfficerRaw = $pick(['extension_officer', 'extensionOfficerId']);

        $medicineRaw = $pick(['medicine', 'medicineId']);
        $administrationRouteRaw = $pick(['administration_route', 'administrationRouteId']);
        $quantity = $pick(['quantity']);
        $dose = $pick(['dose', 'dosage']);
        $nextAdministrationDate = $pick(['next_administration_date', 'nextAdministrationDate']);
        $withdrawalPeriod = $pick(['withdrawal_period', 'withdrawalPeriod']);
        $medicationDate = $pick(['medication_date', 'medicationDate']);
        $nextMedicationDate = $pick(['next_medication_date', 'nextMedicationDate']);

        $conditionTypeRaw = $pick(['condition_type', 'conditionTypeId']);
        $severityRaw = $pick(['severity', 'severityId']);
        $causeRiskRaw = $pick(['cause_risk', 'causeRiskId']);
        $breedingStatusRaw = $pick(['breeding_status', 'breedingStatusId']);
        $healingStatusRaw = $pick(['healing_status', 'healingStatusId']);
        $followUpDate = $pick(['follow_up_date', 'followUpDate']);

        $milkQuantity = $pick(['milk_quantity', 'amount']);
        $session = $pick(['session', 'collection_session']);
        $milkingMethodRaw = $pick(['milking_method', 'milkingMethodId']);
        $lactometerReading = $pick(['lactometer_reading', 'lactometerReading']);
        $totalSolids = $pick(['total_solids', 'totalSolids']);
        $protein = $pick(['protein']);

        $productionDate = $pick(['production_date']);
        $totalEggs = $pick(['total_eggs']);
        $brokenEggs = $pick(['broken_eggs']);
        $dirtyEggs = $pick(['dirty_eggs']);

        $heatTypeRaw = $pick(['heat_type', 'currentHeatTypeId']);
        $inseminationServiceRaw = $pick(['insemination_service', 'inseminationServiceId']);
        $semenStrawTypeRaw = $pick(['semen_straw_type', 'semenStrawTypeId']);
        $inseminationDate = $pick(['insemination_date', 'inseminationDate']);
        $bullCode = $pick(['bull_code', 'bullCode']);
        $bullBreed = $pick(['bull_breed', 'bullBreed']);

        $testResultRaw = $pick(['test_result', 'testResultId']);
        $noOfMonths = $pick(['no_of_months', 'noOfMonths']);
        $testDate = $pick(['test_date', 'testDate']);

        $startDate = $pick(['start_date', 'startDate']);
        $endDate = $pick(['end_date', 'endDate']);
        $reason = $pick(['reason', 'reasons']);

        $newWeight = $pick(['new_weight', 'newWeight']);
        $oldWeight = $pick(['old_weight', 'oldWeight']);

        $feedingTypeRaw = $pick(['feeding_type', 'feedingTypeId']);
        $amount = $pick(['amount']);
        $nextFeedingTime = $pick(['next_feeding_time', 'nextFeedingTime']);

        $methodRaw = $pick(['method', 'methodId']);
        $markingTypeRaw = $pick(['marking_type', 'markingType']);
        $description = $pick(['description']);

        $newStageRaw = $pick(['new_stage', 'toStageId']);
        $previousStageRaw = $pick(['previous_stage', 'fromStageId']);

        $toFarmUuid = $pick(['to_farm_uuid', 'toFarmUuid']);
        $transporterId = $pick(['transporter_id', 'transporterId']);
        $price = $pick(['price']);
        $transferDate = $pick(['transfer_date', 'transferDate']);

        $disposalTypeRaw = $pick(['disposal_type', 'disposalTypeId']);
        $saleWeight = $pick(['sale_weight', 'saleWeight']);
        $salePrice = $pick(['sale_price', 'salePrice']);
        $buyerName = $pick(['buyer_name', 'buyerName']);

        $remarks = $pick(['remarks', 'notes']);

        return [
            'id' => $record->id,
            'farmUuid' => $record->farm_uuid,
            'farmName' => $record->farm?->name ?? '—',
            'farmerName' => $farmerName ?: '—',
            'subjectUuid' => $record->subject_uuid,
            'subjectIdentifier' => $subjectIdentifier,
            'subjectLabel' => $subjectLabel,
            'speciesName' => $speciesName,
            'eventCode' => $record->event_code,
            'eventName' => $eventName,
            'behaviorCode' => $record->behavior_code,
            'familyCode' => $familyCode ? Str::headline($familyCode) : '—',
            'occurredAt' => $record->occurred_at?->toISOString(),
            'recordStatus' => $record->record_status ?? 'confirmed',
            'revision' => (int) ($record->revision ?? 1),
            'payloadSummary' => $this->formatPayloadSummary($payload),
            // Resolved display fields for specific event tables & dialogs
            'bornAlive' => $bornAlive,
            'bornDead' => $bornDead,
            'birthType' => $this->resolveValueLabel($birthTypeRaw),
            'birthProblem' => $this->resolveValueLabel($birthProblemRaw),
            'reproductiveProblem' => $this->resolveValueLabel($reproductiveProblemRaw),
            'labourStartedOn' => $labourStartedOn,
            'abortionDate' => $abortionDate,
            'vaccinationNo' => $vaccinationNo,
            'vaccine' => $this->resolveValueLabel($vaccineRaw),
            'disease' => $this->resolveValueLabel($diseaseRaw),
            'vet' => $this->resolveValueLabel($vetRaw),
            'extensionOfficer' => $this->resolveValueLabel($extensionOfficerRaw),
            'medicine' => $this->resolveValueLabel($medicineRaw),
            'administrationRoute' => $this->resolveValueLabel($administrationRouteRaw),
            'quantity' => $quantity,
            'dose' => $dose,
            'nextAdministrationDate' => $nextAdministrationDate,
            'withdrawalPeriod' => $withdrawalPeriod,
            'medicationDate' => $medicationDate,
            'nextMedicationDate' => $nextMedicationDate,
            'conditionType' => $this->resolveValueLabel($conditionTypeRaw),
            'severity' => $this->resolveValueLabel($severityRaw),
            'causeRisk' => $this->resolveValueLabel($causeRiskRaw),
            'breedingStatus' => $this->resolveValueLabel($breedingStatusRaw),
            'healingStatus' => $this->resolveValueLabel($healingStatusRaw),
            'followUpDate' => $followUpDate,
            'milkQuantity' => $milkQuantity,
            'session' => $this->resolveValueLabel($session),
            'milkingMethod' => $this->resolveValueLabel($milkingMethodRaw),
            'lactometerReading' => $lactometerReading,
            'totalSolids' => $totalSolids,
            'protein' => $protein,
            'productionDate' => $productionDate,
            'totalEggs' => $totalEggs,
            'brokenEggs' => $brokenEggs,
            'dirtyEggs' => $dirtyEggs,
            'heatType' => $this->resolveValueLabel($heatTypeRaw),
            'inseminationService' => $this->resolveValueLabel($inseminationServiceRaw),
            'semenStrawType' => $this->resolveValueLabel($semenStrawTypeRaw),
            'inseminationDate' => $inseminationDate,
            'bullCode' => $bullCode,
            'bullBreed' => $bullBreed,
            'testResult' => $this->resolveValueLabel($testResultRaw),
            'noOfMonths' => $noOfMonths,
            'testDate' => $testDate,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reason' => $reason,
            'newWeight' => $newWeight,
            'oldWeight' => $oldWeight,
            'feedingType' => $this->resolveValueLabel($feedingTypeRaw),
            'amount' => $amount,
            'nextFeedingTime' => $nextFeedingTime,
            'method' => $this->resolveValueLabel($methodRaw),
            'markingType' => $this->resolveValueLabel($markingTypeRaw),
            'description' => $description,
            'newStage' => $this->resolveValueLabel($newStageRaw),
            'previousStage' => $this->resolveValueLabel($previousStageRaw),
            'toFarmUuid' => $toFarmUuid,
            'destinationFarmName' => $this->resolveFarmName($toFarmUuid),
            'transporterId' => $transporterId,
            'price' => $price,
            'transferDate' => $transferDate,
            'disposalType' => $this->resolveValueLabel($disposalTypeRaw),
            'saleWeight' => $saleWeight,
            'salePrice' => $salePrice,
            'buyerName' => $buyerName,
            'remarks' => $remarks,
            'createdAt' => $record->created_at?->toISOString(),
            'updatedAt' => $record->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $basePayload
     * @return array<string, mixed>
     */
    private function extractPayloadFromRequest(Request $request, array $basePayload = []): array
    {
        $payloadKeys = [
            'bornAlive' => 'born_alive',
            'bornDead' => 'born_dead',
            'birthType' => 'birth_type',
            'birthProblem' => 'birth_problem',
            'reproductiveProblem' => 'reproductive_problem',
            'labourStartedOn' => 'labour_started_on',
            'abortionDate' => 'abortion_date',
            'vaccinationNo' => 'vaccination_no',
            'vaccine' => 'vaccine',
            'disease' => 'disease',
            'vet' => 'vet',
            'extensionOfficer' => 'extension_officer',
            'medicine' => 'medicine',
            'administrationRoute' => 'administration_route',
            'quantity' => 'quantity',
            'dose' => 'dose',
            'nextAdministrationDate' => 'next_administration_date',
            'withdrawalPeriod' => 'withdrawal_period',
            'medicationDate' => 'medication_date',
            'nextMedicationDate' => 'next_medication_date',
            'conditionType' => 'condition_type',
            'severity' => 'severity',
            'causeRisk' => 'cause_risk',
            'breedingStatus' => 'breeding_status',
            'healingStatus' => 'healing_status',
            'followUpDate' => 'follow_up_date',
            'milkQuantity' => 'milk_quantity',
            'session' => 'session',
            'milkingMethod' => 'milking_method',
            'lactometerReading' => 'lactometer_reading',
            'totalSolids' => 'total_solids',
            'protein' => 'protein',
            'productionDate' => 'production_date',
            'totalEggs' => 'total_eggs',
            'brokenEggs' => 'broken_eggs',
            'dirtyEggs' => 'dirty_eggs',
            'heatType' => 'heat_type',
            'inseminationService' => 'insemination_service',
            'semenStrawType' => 'semen_straw_type',
            'inseminationDate' => 'insemination_date',
            'bullCode' => 'bull_code',
            'bullBreed' => 'bull_breed',
            'testResult' => 'test_result',
            'noOfMonths' => 'no_of_months',
            'testDate' => 'test_date',
            'startDate' => 'start_date',
            'endDate' => 'end_date',
            'reason' => 'reason',
            'newWeight' => 'new_weight',
            'oldWeight' => 'old_weight',
            'feedingType' => 'feeding_type',
            'amount' => 'amount',
            'nextFeedingTime' => 'next_feeding_time',
            'method' => 'method',
            'markingType' => 'marking_type',
            'description' => 'description',
            'newStage' => 'new_stage',
            'previousStage' => 'previous_stage',
            'toFarmUuid' => 'to_farm_uuid',
            'transporterId' => 'transporter_id',
            'price' => 'price',
            'transferDate' => 'transfer_date',
            'disposalType' => 'disposal_type',
            'saleWeight' => 'sale_weight',
            'salePrice' => 'sale_price',
            'buyerName' => 'buyer_name',
            'remarks' => 'remarks',
        ];

        $payload = $basePayload;
        foreach ($payloadKeys as $camelKey => $snakeKey) {
            if ($request->has($camelKey)) {
                $val = $request->input($camelKey);
                if ($val !== null && $val !== '') {
                    $payload[$snakeKey] = $val;
                }
            } elseif ($request->has($snakeKey)) {
                $val = $request->input($snakeKey);
                if ($val !== null && $val !== '') {
                    $payload[$snakeKey] = $val;
                }
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatPayloadSummary(array $payload): string
    {
        $ignored = ['legacy_table', 'legacy_uuid', 'legacy_id', 'uuid', 'id'];
        $parts = [];

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $ignored, true) || $value === null || $value === '' || $value === []) {
                continue;
            }
            $label = $this->humanizeCode((string) $key);
            if (is_scalar($value)) {
                $parts[] = "{$label}: ".$this->resolveValueLabel((string) $value);
            }
            if (count($parts) >= 4) {
                break;
            }
        }

        return $parts ? implode(' · ', $parts) : '—';
    }

    private function resolveValueLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $stringValue = (string) $value;
        $labels = $this->lookupLabels();

        if (isset($labels[$stringValue])) {
            return $labels[$stringValue];
        }

        if (str_contains($stringValue, '_')) {
            return $this->humanizeCode($stringValue);
        }

        return $stringValue;
    }

    /**
     * @return array<string, string>
     */
    private function lookupLabels(): array
    {
        if ($this->lookupLabelsCache !== null) {
            return $this->lookupLabelsCache;
        }

        $map = [];
        foreach (LookupOption::query()->get(['code', 'translations_json']) as $option) {
            $translations = is_array($option->translations_json) ? $option->translations_json : [];
            $map[$option->code] = $translations['en']['label'] ?? $this->humanizeCode($option->code);
        }

        foreach (Vaccine::query()->get(['uuid', 'name']) as $vaccine) {
            if ($vaccine->uuid) {
                $map[$vaccine->uuid] = $vaccine->name;
            }
        }

        return $this->lookupLabelsCache = $map;
    }

    private function resolveFarmName(mixed $farmUuid): ?string
    {
        if (! is_string($farmUuid) || $farmUuid === '') {
            return null;
        }
        if ($this->farmNamesCache === null) {
            $this->farmNamesCache = Farm::query()->pluck('name', 'uuid')->all();
        }

        return $this->farmNamesCache[$farmUuid] ?? '—';
    }

    private function humanizeCode(?string $code): string
    {
        if (! $code) {
            return '—';
        }
        $clean = preg_replace('/^record_/', '', $code) ?? $code;

        return Str::headline(str_replace(['_', '-'], ' ', $clean));
    }

    private function inferBehaviorCode(string $eventCode): string
    {
        foreach (self::catalog() as $item) {
            if ($item['code'] === $eventCode && $item['behavior']) {
                return $item['behavior'];
            }
        }

        return 'custom';
    }

    private function inferFamilyCode(?string $eventCode): ?string
    {
        foreach (self::catalog() as $item) {
            if ($item['code'] === $eventCode && $item['family']) {
                return $item['family'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsPayload(string $resource): array
    {
        $farms = Farm::query()
            ->with('farmer:id,firstName,middleName,surname')
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'uuid', 'name', 'referenceNo', 'farmerId'])
            ->map(function (Farm $farm): array {
                $farmerName = $farm->farmer
                    ? trim(collect([$farm->farmer->firstName, $farm->farmer->middleName, $farm->farmer->surname])->filter()->implode(' '))
                    : null;

                return [
                    'value' => $farm->uuid,
                    'label' => $farm->name,
                    'description' => collect([$farm->referenceNo, $farmerName ? "Farmer: {$farmerName}" : null])->filter()->implode(' · '),
                ];
            })
            ->values();

        if ($resource === 'identification-events') {
            $livestock = Livestock::query()
                ->with('farm:id,uuid,name')
                ->orderBy('identificationNumber')
                ->limit(250)
                ->get(['id', 'uuid', 'identificationNumber', 'name', 'farmUuid'])
                ->map(fn (Livestock $item) => [
                    'value' => $item->uuid,
                    'label' => trim(($item->identificationNumber ?: 'Untagged').($item->name ? ' · '.$item->name : '')),
                    'description' => $item->farm?->name,
                ])
                ->values();

            return [
                'farms' => $farms,
                'livestock' => $livestock,
            ];
        }

        $subjects = AnimalSubject::query()
            ->with([
                'species:id,uuid,code,name,translations_json',
                'identifiers:id,subject_uuid,display_value,status',
                'farm:id,uuid,name',
            ])
            ->orderByDesc('id')
            ->limit(250)
            ->get(['id', 'uuid', 'display_name', 'species_uuid', 'farm_uuid'])
            ->map(function (AnimalSubject $subject): array {
                $speciesTranslations = $subject->species?->translations_json;
                $speciesName = $subject->species?->name
                    ?: (is_array($speciesTranslations)
                        ? ($speciesTranslations['en']['name'] ?? $subject->species?->code)
                        : $subject->species?->code);
                $primaryTag = $subject->relationLoaded('identifiers')
                    ? $subject->identifiers->first()?->display_value
                    : null;
                $label = $primaryTag && $subject->display_name && $subject->display_name !== $primaryTag
                    ? "{$primaryTag} · {$subject->display_name}"
                    : ($primaryTag ?: ($subject->display_name ?: $subject->uuid));

                return [
                    'value' => $subject->uuid,
                    'label' => $label,
                    'description' => collect([$speciesName, $subject->farm?->name])->filter()->implode(' · '),
                ];
            })
            ->values();

        $eventCodes = collect(self::catalog())
            ->filter(fn (array $item, string $slug) => $slug !== 'all-events' && $slug !== 'identification-events' && $item['code'] !== null)
            ->map(fn (array $item) => [
                'value' => $item['code'],
                'label' => $item['label'],
                'description' => $item['family'] ? Str::headline($item['family']) : null,
            ])
            ->values();

        return [
            'farms' => $farms,
            'subjects' => $subjects,
            'eventCodes' => $eventCodes,
        ];
    }
}
