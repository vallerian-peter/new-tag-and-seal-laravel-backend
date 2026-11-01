<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\IdentityCardType;
use App\Models\LegalStatus;
use App\Models\LivestockMarkingType;
use App\Models\MedicineType;
use App\Models\SchoolLevel;
use App\Models\TailDockingMethod;
use App\Models\TeethClippingMethod;
use App\Models\Vaccine;
use App\Models\VaccineType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReferenceResourceController extends Controller
{
    private const RESOURCES = [
        'vaccines' => Vaccine::class,
        'vaccine-types' => VaccineType::class,
        'medicine-types' => MedicineType::class,
        'marking-types' => LivestockMarkingType::class,
        'tail-docking-methods' => TailDockingMethod::class,
        'teeth-clipping-methods' => TeethClippingMethod::class,
        'id-card-types' => IdentityCardType::class,
        'school-levels' => SchoolLevel::class,
        'legal-statuses' => LegalStatus::class,
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        $perPage = min(max((int) $request->integer('per_page', 10), 5), 100);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $sort = (string) $request->query('sort', 'desc');

        $query = $this->baseQuery($resource, $modelClass);

        if ($resource === 'vaccines' && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $this->applySearch($query, $resource, $search);
        }

        $this->applySort($query, $sort);

        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'status' => true,
            'message' => 'Records fetched successfully.',
            'data' => collect($paginator->items())
                ->map(fn (Model $record): array => $this->present($resource, $record))
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'stats' => $this->stats($resource),
            ],
        ]);
    }

    public function options(Request $request, string $resource): JsonResponse
    {
        $this->resolveModelClass($resource);

        $data = match ($resource) {
            'vaccines' => [
                'farms' => Farm::query()
                    ->with('farmer:id,firstName,middleName,surname')
                    ->orderBy('name')
                    ->get(['id', 'uuid', 'farmerId', 'name', 'referenceNo'])
                    ->map(function (Farm $f): array {
                        $farmerName = $f->farmer
                            ? trim(implode(' ', array_filter([$f->farmer->firstName, $f->farmer->middleName, $f->farmer->surname])))
                            : '';

                        return [
                            'value' => $f->uuid,
                            'label' => $f->name,
                            'description' => implode(' · ', array_filter([
                                $f->referenceNo,
                                $farmerName !== '' ? "Farmer: {$farmerName}" : null,
                            ])) ?: null,
                        ];
                    })
                    ->values()
                    ->all(),
                'vaccineTypes' => VaccineType::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (VaccineType $t): array => [
                        'value' => (string) $t->id,
                        'label' => $t->name,
                    ])
                    ->values()
                    ->all(),
            ],
            default => [],
        };

        return response()->json([
            'status' => true,
            'message' => 'Options fetched successfully.',
            'data' => $data,
        ]);
    }

    public function show(string $resource, int $record): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        $instance = $this->baseQuery($resource, $modelClass)->findOrFail($record);

        return response()->json([
            'status' => true,
            'message' => 'Record fetched successfully.',
            'data' => $this->present($resource, $instance),
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        $validated = $request->validate($this->rules($resource));

        if ($resource === 'vaccines') {
            $validated['uuid'] = (string) Str::uuid();
            $validated['status'] = $validated['status'] ?? 'active';
        }

        /** @var Model $instance */
        $instance = $modelClass::query()->create($validated);
        $fresh = $this->baseQuery($resource, $modelClass)->findOrFail($instance->getKey());

        return response()->json([
            'status' => true,
            'message' => $this->singularLabel($resource).' created successfully.',
            'data' => $this->present($resource, $fresh),
        ], 201);
    }

    public function update(Request $request, string $resource, int $record): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        /** @var Model $instance */
        $instance = $modelClass::query()->findOrFail($record);

        $validated = $request->validate($this->rules($resource, $instance, true));
        unset($validated['uuid']);

        $instance->fill($validated);
        $instance->save();

        $fresh = $this->baseQuery($resource, $modelClass)->findOrFail($instance->getKey());

        return response()->json([
            'status' => true,
            'message' => $this->singularLabel($resource).' updated successfully.',
            'data' => $this->present($resource, $fresh),
        ]);
    }

    public function destroy(string $resource, int $record): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        /** @var Model $instance */
        $instance = $modelClass::query()->findOrFail($record);
        $instance->delete();

        return response()->json([
            'status' => true,
            'message' => $this->singularLabel($resource).' deleted successfully.',
            'data' => null,
        ]);
    }

    /**
     * @return class-string<Model>
     */
    private function resolveModelClass(string $resource): string
    {
        if (! isset(self::RESOURCES[$resource])) {
            abort(404, "Unsupported reference resource [{$resource}].");
        }

        return self::RESOURCES[$resource];
    }

    private function singularLabel(string $resource): string
    {
        return match ($resource) {
            'vaccines' => 'Vaccine',
            'vaccine-types' => 'Vaccine type',
            'medicine-types' => 'Medicine type',
            'marking-types' => 'Marking type',
            'tail-docking-methods' => 'Tail docking method',
            'teeth-clipping-methods' => 'Teeth clipping method',
            'id-card-types' => 'ID card type',
            'school-levels' => 'School level',
            'legal-statuses' => 'Legal status',
            default => 'Reference record',
        };
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function baseQuery(string $resource, string $modelClass): Builder
    {
        $query = $modelClass::query();

        return match ($resource) {
            'vaccines' => $query->with([
                'farm:id,uuid,name,referenceNo',
                'vaccineType:id,name',
            ]),
            'vaccine-types' => $query->withCount(['vaccines']),
            'id-card-types', 'school-levels' => $query->withCount(['farmers']),
            'legal-statuses' => $query->withCount(['farms']),
            default => $query,
        };
    }

    private function applySearch(Builder $query, string $resource, string $search): void
    {
        $like = "%{$search}%";

        $query->where(function (Builder $q) use ($resource, $like): void {
            $q->where('name', 'like', $like);

            if ($resource === 'vaccines') {
                $q->orWhere('lot', 'like', $like)
                    ->orWhere('dose', 'like', $like)
                    ->orWhere('formulationType', 'like', $like)
                    ->orWhere('vaccineSchedule', 'like', $like)
                    ->orWhereHas('farm', fn (Builder $fq) => $fq->where('name', 'like', $like))
                    ->orWhereHas('vaccineType', fn (Builder $vq) => $vq->where('name', 'like', $like));
            }
        });
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'asc', 'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'alpha-asc', 'name_asc' => $query->orderBy('name')->orderBy('id'),
            'alpha-desc', 'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    private function stats(string $resource): array
    {
        return match ($resource) {
            'vaccines' => [
                ['label' => 'Total vaccines', 'value' => Vaccine::query()->count(), 'note' => 'Registered vaccine inventory lots'],
                ['label' => 'Active lots', 'value' => Vaccine::query()->where('status', 'active')->count(), 'note' => 'Vaccine lots currently active for use'],
                ['label' => 'Vaccine types', 'value' => VaccineType::query()->count(), 'note' => 'Approved vaccine type classifications'],
            ],
            'vaccine-types' => [
                ['label' => 'Total vaccine types', 'value' => VaccineType::query()->count(), 'note' => 'Approved vaccine product categories'],
                ['label' => 'Types in use', 'value' => Vaccine::query()->distinct('vaccineTypeId')->count('vaccineTypeId'), 'note' => 'Vaccine types with registered lots'],
                ['label' => 'Linked vaccines', 'value' => Vaccine::query()->count(), 'note' => 'Total vaccines mapped to types'],
            ],
            'medicine-types' => [
                ['label' => 'Total medicine types', 'value' => MedicineType::query()->count(), 'note' => 'Approved veterinary medicine categories'],
                ['label' => 'Active catalogue', 'value' => MedicineType::query()->count(), 'note' => 'Available in treatment & medicine logs'],
                ['label' => 'Latest additions', 'value' => MedicineType::query()->where('created_at', '>=', now()->subDays(30))->count(), 'note' => 'Added in the last 30 days'],
            ],
            'marking-types' => [
                ['label' => 'Total marking types', 'value' => LivestockMarkingType::query()->count(), 'note' => 'Supported livestock marking methods'],
                ['label' => 'Active catalogue', 'value' => LivestockMarkingType::query()->count(), 'note' => 'Available in marking event forms'],
                ['label' => 'Latest additions', 'value' => LivestockMarkingType::query()->where('created_at', '>=', now()->subDays(30))->count(), 'note' => 'Added in the last 30 days'],
            ],
            'tail-docking-methods' => [
                ['label' => 'Total docking methods', 'value' => TailDockingMethod::query()->count(), 'note' => 'Approved tail docking techniques'],
                ['label' => 'Active catalogue', 'value' => TailDockingMethod::query()->count(), 'note' => 'Available in tail docking logs'],
                ['label' => 'Latest additions', 'value' => TailDockingMethod::query()->where('created_at', '>=', now()->subDays(30))->count(), 'note' => 'Added in the last 30 days'],
            ],
            'teeth-clipping-methods' => [
                ['label' => 'Total clipping methods', 'value' => TeethClippingMethod::query()->count(), 'note' => 'Approved teeth clipping procedures'],
                ['label' => 'Active catalogue', 'value' => TeethClippingMethod::query()->count(), 'note' => 'Available in teeth clipping logs'],
                ['label' => 'Latest additions', 'value' => TeethClippingMethod::query()->where('created_at', '>=', now()->subDays(30))->count(), 'note' => 'Added in the last 30 days'],
            ],
            'id-card-types' => [
                ['label' => 'Total ID card types', 'value' => IdentityCardType::query()->count(), 'note' => 'Accepted national and personal ID documents'],
                ['label' => 'Types in use', 'value' => Farmer::query()->whereNotNull('identityCardTypeId')->distinct('identityCardTypeId')->count('identityCardTypeId'), 'note' => 'ID types used in farmer profiles'],
                ['label' => 'Verified farmers', 'value' => Farmer::query()->whereNotNull('identityCardTypeId')->count(), 'note' => 'Farmers with recorded ID card type'],
            ],
            'school-levels' => [
                ['label' => 'Total school levels', 'value' => SchoolLevel::query()->count(), 'note' => 'Education levels in profile catalogue'],
                ['label' => 'Levels in use', 'value' => Farmer::query()->whereNotNull('schoolLevelId')->distinct('schoolLevelId')->count('schoolLevelId'), 'note' => 'Education levels assigned to farmers'],
                ['label' => 'Linked farmers', 'value' => Farmer::query()->whereNotNull('schoolLevelId')->count(), 'note' => 'Farmers with recorded education level'],
            ],
            'legal-statuses' => [
                ['label' => 'Total legal statuses', 'value' => LegalStatus::query()->count(), 'note' => 'Legal structures for farms and organizations'],
                ['label' => 'Statuses in use', 'value' => Farm::query()->whereNotNull('legalStatusId')->distinct('legalStatusId')->count('legalStatusId'), 'note' => 'Legal statuses assigned to farms'],
                ['label' => 'Linked farms', 'value' => Farm::query()->whereNotNull('legalStatusId')->count(), 'note' => 'Farms with recorded legal status'],
            ],
            default => [],
        };
    }

    private function rules(string $resource, ?Model $record = null, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return match ($resource) {
            'vaccines' => [
                'name' => [$req, 'string', 'max:255'],
                'farmUuid' => [$req, 'string', 'exists:farms,uuid'],
                'lot' => [$req, 'string', 'max:255'],
                'formulationType' => [$req, 'string', Rule::in(['live-attenuated', 'inactivated'])],
                'dose' => ['nullable', 'string', 'max:255'],
                'status' => [$req, 'string', Rule::in(['active', 'inactive', 'expired'])],
                'vaccineTypeId' => [$req, 'integer', 'exists:vaccine_types,id'],
                'vaccineSchedule' => ['nullable', 'string', Rule::in(['regular', 'booster', 'seasonal', 'emergency'])],
            ],
            'vaccine-types' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('vaccine_types', 'name')->ignore($record?->getKey())],
            ],
            'medicine-types' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('medicine_types', 'name')->ignore($record?->getKey())],
            ],
            'marking-types' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('livestock_marking_types', 'name')->ignore($record?->getKey())],
            ],
            'tail-docking-methods' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('tail_docking_methods', 'name')->ignore($record?->getKey())],
            ],
            'teeth-clipping-methods' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('teeth_clipping_methods', 'name')->ignore($record?->getKey())],
            ],
            'id-card-types' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('identity_card_types', 'name')->ignore($record?->getKey())],
            ],
            'school-levels' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('school_levels', 'name')->ignore($record?->getKey())],
            ],
            'legal-statuses' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('legal_statuses', 'name')->ignore($record?->getKey())],
            ],
            default => [],
        };
    }

    private function present(string $resource, Model $record): array
    {
        $base = [
            'id' => (int) $record->getKey(),
            'name' => (string) $record->getAttribute('name'),
            'createdAt' => $record->getAttribute('created_at')?->toIso8601String(),
            'updatedAt' => $record->getAttribute('updated_at')?->toIso8601String(),
        ];

        if ($resource === 'vaccines' && $record instanceof Vaccine) {
            return array_merge($base, [
                'farmUuid' => $record->farmUuid,
                'farmName' => $record->relationLoaded('farm') ? $record->getRelation('farm')?->name : null,
                'lot' => $record->lot,
                'formulationType' => $record->formulationType,
                'formulationLabel' => match ($record->formulationType) {
                    'live-attenuated' => 'Live Attenuated',
                    'inactivated' => 'Inactivated',
                    default => $record->formulationType,
                },
                'dose' => $record->dose,
                'status' => $record->status ?: 'active',
                'vaccineTypeId' => $record->vaccineTypeId !== null ? (int) $record->vaccineTypeId : null,
                'vaccineTypeName' => $record->relationLoaded('vaccineType') ? $record->getRelation('vaccineType')?->name : null,
                'vaccineSchedule' => $record->vaccineSchedule,
                'scheduleLabel' => match ($record->vaccineSchedule) {
                    'regular' => 'Regular Schedule',
                    'booster' => 'Booster',
                    'seasonal' => 'Seasonal',
                    'emergency' => 'Emergency',
                    default => $record->vaccineSchedule,
                },
            ]);
        }

        if ($resource === 'vaccine-types') {
            return array_merge($base, [
                'vaccinesCount' => (int) ($record->getAttribute('vaccines_count') ?? 0),
            ]);
        }

        if (in_array($resource, ['id-card-types', 'school-levels'], true)) {
            return array_merge($base, [
                'farmersCount' => (int) ($record->getAttribute('farmers_count') ?? 0),
            ]);
        }

        if ($resource === 'legal-statuses') {
            return array_merge($base, [
                'farmsCount' => (int) ($record->getAttribute('farms_count') ?? 0),
            ]);
        }

        return $base;
    }
}
 