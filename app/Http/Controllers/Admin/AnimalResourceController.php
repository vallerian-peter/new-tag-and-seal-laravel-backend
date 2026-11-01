<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Models\Breed;
use App\Models\Farm;
use App\Models\IdentifierType;
use App\Models\Livestock;
use App\Models\LivestockObtainedMethod;
use App\Models\LivestockType;
use App\Models\Specie;
use App\Models\Stage;
use App\Models\SubjectIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AnimalResourceController extends Controller
{
    private const RESOURCES = [
        'animals' => AnimalSubject::class,
        'legacy-livestock' => Livestock::class,
        'animal-types' => LivestockType::class,
        'species' => Specie::class,
        'breeds' => Breed::class,
        'acquisition-methods' => LivestockObtainedMethod::class,
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        $perPage = min(max((int) $request->integer('per_page', 10), 5), 100);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $tab = (string) $request->query('tab', 'all');
        $sort = (string) $request->query('sort', 'desc');

        $query = $this->baseQuery($resource, $modelClass);

        if ($this->isSoftDeletable($resource)) {
            if ($tab === 'deleted') {
                $query->onlyTrashed();
            }
        }

        if ($status !== '' && $status !== 'all') {
            $this->applyStatusFilter($query, $resource, $status);
        }

        if ($search !== '') {
            $this->applySearch($query, $resource, $search);
        }

        $this->applySort($query, $resource, $sort);

        $paginator = $query->paginate($perPage)->withQueryString();
        $items = collect($paginator->items());

        return response()->json([
            'status' => true,
            'message' => 'Records fetched successfully.',
            'data' => $this->presentCollection($resource, $items),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'deletedCount' => $this->deletedCount($resource),
                'stats' => $this->stats($resource),
            ],
        ]);
    }

    public function options(Request $request, string $resource): JsonResponse
    {
        $this->resolveModelClass($resource);

        $data = match ($resource) {
            'animals' => [
                'farms' => $this->farmUuidOptions(),
                'dynamicSpecies' => $this->dynamicSpeciesOptions(),
            ],
            'legacy-livestock' => [
                'farms' => $this->farmUuidOptions(),
                'livestockTypes' => $this->livestockTypeOptions(),
                'species' => $this->speciesOptions(),
                'breeds' => $this->breedOptions(),
                'stages' => $this->stageOptions(),
                'obtainedMethods' => $this->obtainedMethodOptions(),
                'mothers' => $this->parentLivestockOptions('female'),
                'fathers' => $this->parentLivestockOptions('male'),
            ],
            'species', 'breeds' => [
                'livestockTypes' => $this->livestockTypeOptions(),
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
        $query = $this->baseQuery($resource, $modelClass);
        if ($this->isSoftDeletable($resource)) {
            $query->withTrashed();
        }
        $instance = $query->findOrFail($record);

        return response()->json([
            'status' => true,
            'message' => 'Record fetched successfully.',
            'data' => $this->presentCollection($resource, collect([$instance]))[0],
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        $validated = $request->validate($this->rules($resource));

        $instance = DB::transaction(function () use ($resource, $modelClass, $validated): Model {
            if ($resource === 'animals') {
                $primaryTag = isset($validated['primary_tag']) ? trim((string) $validated['primary_tag']) : '';
                unset($validated['primary_tag']);

                $blueprint = AnimalBlueprint::query()
                    ->where('species_uuid', $validated['species_uuid'])
                    ->orderByDesc('version')
                    ->first();

                $validated['uuid'] = (string) Str::uuid();
                $validated['blueprint_uuid'] = $blueprint?->uuid ?? (string) Str::uuid();
                $validated['blueprint_version'] = $blueprint?->version ?? 1;
                $validated['unit_type'] = $validated['unit_type'] ?? 'individual';
                $validated['lifecycle_status'] = $validated['lifecycle_status'] ?? 'active';
                $validated['quantity'] = $validated['quantity'] ?? 1;
                $validated['attributes_json'] = [];

                /** @var AnimalSubject $subject */
                $subject = AnimalSubject::query()->create($validated);

                if ($primaryTag !== '') {
                    $this->syncPrimaryIdentifier($subject, $primaryTag);
                }

                return $subject;
            }

            if ($resource === 'legacy-livestock') {
                $validated['uuid'] = (string) Str::uuid();
                $validated['status'] = $validated['status'] ?? 'active';
                $validated['isIdentified'] = (bool) ($validated['isIdentified'] ?? true);
            }

            return $modelClass::query()->create($validated);
        });

        $fresh = $this->baseQuery($resource, $modelClass)->findOrFail($instance->getKey());

        return response()->json([
            'status' => true,
            'message' => $this->singularLabel($resource).' created successfully.',
            'data' => $this->presentCollection($resource, collect([$fresh]))[0],
        ], 201);
    }

    public function update(Request $request, string $resource, int $record): JsonResponse
    {
        if ($request->boolean('restore') || $request->input('action') === 'restore') {
            return $this->restore($request, $resource, $record);
        }

        $modelClass = $this->resolveModelClass($resource);
        $query = $modelClass::query();
        if ($this->isSoftDeletable($resource)) {
            $query->withTrashed();
        }
        /** @var Model $instance */
        $instance = $query->findOrFail($record);

        $validated = $request->validate($this->rules($resource, $instance, true));

        DB::transaction(function () use ($resource, $instance, $validated): void {
            if ($resource === 'animals') {
                $hasPrimaryTag = array_key_exists('primary_tag', $validated);
                $primaryTag = $hasPrimaryTag ? trim((string) ($validated['primary_tag'] ?? '')) : null;
                unset($validated['primary_tag'], $validated['uuid']);

                $instance->fill($validated);
                $instance->save();

                if ($hasPrimaryTag && $instance instanceof AnimalSubject) {
                    $this->syncPrimaryIdentifier($instance, (string) $primaryTag);
                }

                return;
            }

            unset($validated['uuid']);
            $instance->fill($validated);
            $instance->save();
        });

        $freshQuery = $this->baseQuery($resource, $modelClass);
        if ($this->isSoftDeletable($resource)) {
            $freshQuery->withTrashed();
        }
        $fresh = $freshQuery->findOrFail($instance->getKey());

        return response()->json([
            'status' => true,
            'message' => $this->singularLabel($resource).' updated successfully.',
            'data' => $this->presentCollection($resource, collect([$fresh]))[0],
        ]);
    }

    public function restore(Request $request, string $resource, int $record): JsonResponse
    {
        if (! $this->isSoftDeletable($resource)) {
            abort(400, "Resource [{$resource}] does not support restore.");
        }

        $modelClass = $this->resolveModelClass($resource);
        $instance = $modelClass::onlyTrashed()->findOrFail($record);
        if ($resource === 'animals' && $instance instanceof AnimalSubject && $instance->lifecycle_status === 'archived') {
            $instance->lifecycle_status = 'active';
            $instance->save();
        }
        $instance->restore();

        $fresh = $this->baseQuery($resource, $modelClass)->findOrFail($instance->getKey());

        return response()->json([
            'status' => true,
            'message' => $this->singularLabel($resource).' restored successfully.',
            'data' => $this->presentCollection($resource, collect([$fresh]))[0],
        ]);
    }

    public function destroy(Request $request, string $resource, int $record): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        $force = $request->boolean('force');

        if ($this->isSoftDeletable($resource)) {
            $instance = $modelClass::withTrashed()->findOrFail($record);
            if ($force) {
                if ($resource === 'animals' && $instance instanceof AnimalSubject) {
                    SubjectIdentifier::withTrashed()->where('subject_uuid', $instance->uuid)->forceDelete();
                }
                $instance->forceDelete();

                return response()->json([
                    'status' => true,
                    'message' => $this->singularLabel($resource).' permanently deleted.',
                    'data' => null,
                ]);
            }

            if (! $instance->trashed()) {
                $instance->delete();
            }

            return response()->json([
                'status' => true,
                'message' => $this->singularLabel($resource).' moved to deleted records.',
                'data' => null,
            ]);
        }

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
            abort(404, "Unsupported animal/livestock resource [{$resource}].");
        }

        return self::RESOURCES[$resource];
    }

    private function isSoftDeletable(string $resource): bool
    {
        return in_array($resource, ['animals', 'legacy-livestock'], true);
    }

    private function deletedCount(string $resource): int
    {
        return match ($resource) {
            'animals' => AnimalSubject::onlyTrashed()->count(),
            'legacy-livestock' => Livestock::onlyTrashed()->count(),
            default => 0,
        };
    }

    private function singularLabel(string $resource): string
    {
        return match ($resource) {
            'animals' => 'Animal',
            'legacy-livestock' => 'Livestock record',
            'animal-types' => 'Animal type',
            'species' => 'Species',
            'breeds' => 'Breed',
            'acquisition-methods' => 'Acquisition method',
            default => 'Record',
        };
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function baseQuery(string $resource, string $modelClass): Builder
    {
        $query = $modelClass::query();

        return match ($resource) {
            'animals' => $query->with([
                'species:id,uuid,name,code',
                'farm:id,uuid,name,referenceNo',
                'identifiers:id,uuid,subject_uuid,display_value,status',
            ]),
            'legacy-livestock' => $query->with([
                'farm:id,uuid,name,referenceNo',
                'species:id,name,livestockTypeId',
                'breed:id,name,group,livestockTypeId',
                'livestockType:id,name',
                'stage:id,name',
                'livestockObtainedMethod:id,name',
                'mother:id,uuid,name,identificationNumber',
                'father:id,uuid,name,identificationNumber',
            ]),
            'animal-types' => $query->withCount(['species', 'breeds', 'livestocks']),
            'species' => $query
                ->with(['livestockType:id,name'])
                ->withCount(['livestocks']),
            'breeds' => $query
                ->with(['livestockType:id,name'])
                ->withCount(['livestocks']),
            'acquisition-methods' => $query->withCount(['livestocks']),
            default => $query,
        };
    }

    private function applyStatusFilter(Builder $query, string $resource, string $status): void
    {
        match ($resource) {
            'animals' => $status === 'notActive'
                ? $query->whereIn('lifecycle_status', ['notActive', 'not-active', 'archived'])
                : $query->where('lifecycle_status', $status),
            'legacy-livestock' => $query->where('status', $status),
            'species', 'breeds' => ctype_digit($status) ? $query->where('livestockTypeId', (int) $status) : null,
            default => null,
        };
    }

    private function applySearch(Builder $query, string $resource, string $search): void
    {
        $like = "%{$search}%";

        $query->where(function (Builder $q) use ($resource, $like): void {
            if ($resource === 'animals') {
                $q->where('display_name', 'like', $like)
                    ->orWhere('current_stage_code', 'like', $like)
                    ->orWhereHas('species', fn (Builder $sq) => $sq->where('name', 'like', $like))
                    ->orWhereHas('farm', fn (Builder $fq) => $fq->where('name', 'like', $like))
                    ->orWhereHas('identifiers', fn (Builder $iq) => $iq->where('display_value', 'like', $like));

                return;
            }

            if ($resource === 'legacy-livestock') {
                $q->where('name', 'like', $like)
                    ->orWhere('identificationNumber', 'like', $like)
                    ->orWhere('rfidTagId', 'like', $like)
                    ->orWhere('barcodeTagId', 'like', $like)
                    ->orWhere('dummyTagId', 'like', $like)
                    ->orWhereHas('farm', fn (Builder $fq) => $fq->where('name', 'like', $like))
                    ->orWhereHas('species', fn (Builder $sq) => $sq->where('name', 'like', $like))
                    ->orWhereHas('breed', fn (Builder $bq) => $bq->where('name', 'like', $like))
                    ->orWhereHas('livestockType', fn (Builder $tq) => $tq->where('name', 'like', $like));

                return;
            }

            $q->where('name', 'like', $like);

            if ($resource === 'breeds') {
                $q->orWhere('group', 'like', $like);
            }

            if (in_array($resource, ['species', 'breeds'], true)) {
                $q->orWhereHas('livestockType', fn (Builder $tq) => $tq->where('name', 'like', $like));
            }
        });
    }

    private function applySort(Builder $query, string $resource, string $sort): void
    {
        $nameCol = $resource === 'animals' ? 'display_name' : 'name';

        match ($sort) {
            'asc', 'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'alpha-asc', 'name_asc' => $query->orderBy($nameCol)->orderBy('id'),
            'alpha-desc', 'name_desc' => $query->orderByDesc($nameCol)->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    private function stats(string $resource): array
    {
        return match ($resource) {
            'animals' => [
                ['label' => 'Total animals', 'value' => AnimalSubject::query()->count(), 'note' => 'Active dynamic animal subjects and groups'],
                ['label' => 'Tagged subjects', 'value' => SubjectIdentifier::query()->where('status', 'active')->distinct('subject_uuid')->count('subject_uuid'), 'note' => 'Animals with active RFID or visual tags'],
                ['label' => 'Farms represented', 'value' => AnimalSubject::query()->distinct('farm_uuid')->count('farm_uuid'), 'note' => 'Farms managing dynamic animal subjects'],
            ],
            'legacy-livestock' => [
                ['label' => 'Total livestock', 'value' => Livestock::query()->count(), 'note' => 'Active legacy livestock records'],
                ['label' => 'Identified animals', 'value' => Livestock::query()->where('isIdentified', true)->count(), 'note' => 'Livestock with official identification'],
                ['label' => 'Farms represented', 'value' => Livestock::query()->distinct('farmUuid')->count('farmUuid'), 'note' => 'Farms with registered livestock'],
            ],
            'animal-types' => [
                ['label' => 'Total animal types', 'value' => LivestockType::query()->count(), 'note' => 'High-level livestock classifications'],
                ['label' => 'Linked species', 'value' => Specie::query()->count(), 'note' => 'Species mapped to livestock types'],
                ['label' => 'Linked breeds', 'value' => Breed::query()->count(), 'note' => 'Breeds mapped to livestock types'],
            ],
            'species' => [
                ['label' => 'Total species', 'value' => Specie::query()->count(), 'note' => 'Registered livestock species'],
                ['label' => 'Animal types covered', 'value' => Specie::query()->distinct('livestockTypeId')->count('livestockTypeId'), 'note' => 'Livestock types with registered species'],
                ['label' => 'Linked livestock', 'value' => Livestock::query()->count(), 'note' => 'Livestock assigned to species'],
            ],
            'breeds' => [
                ['label' => 'Total breeds', 'value' => Breed::query()->count(), 'note' => 'Registered livestock breeds'],
                ['label' => 'Breed groups', 'value' => Breed::query()->distinct('group')->count('group'), 'note' => 'Distinct breed group categories'],
                ['label' => 'Linked livestock', 'value' => Livestock::query()->count(), 'note' => 'Livestock assigned to breeds'],
            ],
            'acquisition-methods' => [
                ['label' => 'Total methods', 'value' => LivestockObtainedMethod::query()->count(), 'note' => 'Approved herd entry and acquisition methods'],
                ['label' => 'Methods in use', 'value' => Livestock::query()->whereNotNull('livestockObtainedMethodId')->distinct('livestockObtainedMethodId')->count('livestockObtainedMethodId'), 'note' => 'Methods referenced by livestock records'],
                ['label' => 'Linked livestock', 'value' => Livestock::query()->whereNotNull('livestockObtainedMethodId')->count(), 'note' => 'Livestock with recorded acquisition method'],
            ],
            default => [],
        };
    }

    private function rules(string $resource, ?Model $record = null, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return match ($resource) {
            'animals' => [
                'display_name' => [$req, 'string', 'max:255'],
                'farm_uuid' => [$req, 'string', 'exists:farms,uuid'],
                'species_uuid' => [$req, 'string', 'exists:animal_species,uuid'],
                'unit_type' => ['nullable', 'string', Rule::in(['individual', 'group'])],
                'sex_code' => ['nullable', 'string', Rule::in(['male', 'female', 'unknown'])],
                'birth_or_start_date' => ['nullable', 'date'],
                'quantity' => ['nullable', 'integer', 'min:1'],
                'current_stage_code' => ['nullable', 'string', 'max:100'],
                'lifecycle_status' => ['nullable', 'string', Rule::in(['active', 'notActive'])],
                'primary_tag' => ['nullable', 'string', 'max:255'],
            ],
            'legacy-livestock' => [
                'name' => [$req, 'string', 'max:255'],
                'farmUuid' => [$req, 'string', 'exists:farms,uuid'],
                'identificationNumber' => ['nullable', 'string', 'max:255'],
                'dummyTagId' => ['nullable', 'string', 'max:255'],
                'barcodeTagId' => ['nullable', 'string', 'max:255'],
                'rfidTagId' => ['nullable', 'string', 'max:255'],
                'livestockTypeId' => [$req, 'integer', 'exists:livestock_types,id'],
                'speciesId' => [$req, 'integer', 'exists:species,id'],
                'breedId' => [$req, 'integer', 'exists:breeds,id'],
                'stageId' => ['nullable', 'integer', 'exists:stages,id'],
                'gender' => [$req, 'string', Rule::in(['male', 'female'])],
                'dateOfBirth' => [$req, 'date'],
                'motherUuid' => ['nullable', 'string', 'exists:livestocks,uuid'],
                'fatherUuid' => ['nullable', 'string', 'exists:livestocks,uuid'],
                'isIdentified' => ['nullable', 'boolean'],
                'status' => ['nullable', 'string', Rule::in(['active', 'notActive'])],
                'livestockObtainedMethodId' => ['nullable', 'integer', 'exists:livestock_obtained_methods,id'],
                'dateFirstEnteredToFarm' => ['nullable', 'date'],
                'weightAsOnRegistration' => ['nullable', 'numeric'],
                'primaryColor' => ['nullable', 'string', 'max:64'],
                'secondaryColor' => ['nullable', 'string', 'max:64'],
            ],
            'animal-types' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('livestock_types', 'name')->ignore($record?->getKey())],
            ],
            'species' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('species', 'name')->ignore($record?->getKey())],
                'livestockTypeId' => [$req, 'integer', 'exists:livestock_types,id'],
            ],
            'breeds' => [
                'name' => [$req, 'string', 'max:255'],
                'group' => [$req, 'string', 'max:255'],
                'livestockTypeId' => [$req, 'integer', 'exists:livestock_types,id'],
            ],
            'acquisition-methods' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('livestock_obtained_methods', 'name')->ignore($record?->getKey())],
            ],
            default => [],
        };
    }

    private function presentCollection(string $resource, Collection $records): array
    {
        return $records
            ->map(fn (Model $record): array => $this->present($resource, $record))
            ->values()
            ->all();
    }

    private function present(string $resource, Model $record): array
    {
        $base = [
            'id' => (int) $record->getKey(),
            'createdAt' => $record->getAttribute('created_at')?->toIso8601String(),
            'updatedAt' => $record->getAttribute('updated_at')?->toIso8601String(),
        ];

        if ($resource === 'animals' && $record instanceof AnimalSubject) {
            $identifiers = $record->relationLoaded('identifiers')
                ? $record->getRelation('identifiers')->pluck('display_value')->values()->all()
                : [];
            $unitType = $record->unit_type instanceof \BackedEnum ? $record->unit_type->value : (string) ($record->unit_type ?? 'individual');
            $sexCode = $record->sex_code instanceof \BackedEnum ? $record->sex_code->value : ($record->sex_code ? (string) $record->sex_code : null);

            return array_merge($base, [
                'display_name' => $record->display_name ?: 'Unnamed',
                'name' => $record->display_name ?: 'Unnamed',
                'farm_uuid' => $record->farm_uuid,
                'farmName' => $record->relationLoaded('farm') ? $record->getRelation('farm')?->name : null,
                'species_uuid' => $record->species_uuid,
                'speciesName' => $record->relationLoaded('species') ? $record->getRelation('species')?->name : null,
                'unit_type' => $unitType,
                'unitTypeLabel' => $unitType === 'group' ? 'Group' : 'Individual',
                'sex_code' => $sexCode,
                'birth_or_start_date' => $record->birth_or_start_date?->format('Y-m-d'),
                'quantity' => (int) ($record->quantity ?? 1),
                'current_stage_code' => $record->current_stage_code,
                'lifecycle_status' => in_array($record->lifecycle_status, ['notActive', 'not-active', 'archived'], true) ? 'notActive' : 'active',
                'primary_tag' => $identifiers[0] ?? '',
                'identifiersText' => count($identifiers) > 0 ? implode(', ', $identifiers) : '—',
                'deletedAt' => $record->deleted_at?->toIso8601String(),
            ]);
        }

        if ($resource === 'legacy-livestock' && $record instanceof Livestock) {
            $mother = $record->relationLoaded('mother') ? $record->getRelation('mother') : null;
            $father = $record->relationLoaded('father') ? $record->getRelation('father') : null;

            return array_merge($base, [
                'name' => (string) $record->name,
                'identificationNumber' => $record->identificationNumber,
                'dummyTagId' => $record->dummyTagId,
                'barcodeTagId' => $record->barcodeTagId,
                'rfidTagId' => $record->rfidTagId,
                'farmUuid' => $record->farmUuid,
                'farmName' => $record->relationLoaded('farm') ? $record->getRelation('farm')?->name : null,
                'livestockTypeId' => $record->livestockTypeId !== null ? (int) $record->livestockTypeId : null,
                'livestockTypeName' => $record->relationLoaded('livestockType') ? $record->getRelation('livestockType')?->name : null,
                'speciesId' => $record->speciesId !== null ? (int) $record->speciesId : null,
                'speciesName' => $record->relationLoaded('species') ? $record->getRelation('species')?->name : null,
                'breedId' => $record->breedId !== null ? (int) $record->breedId : null,
                'breedName' => $record->relationLoaded('breed') ? $record->getRelation('breed')?->name : null,
                'stageId' => $record->stageId !== null ? (int) $record->stageId : null,
                'stageName' => $record->relationLoaded('stage') ? $record->getRelation('stage')?->name : null,
                'gender' => $record->gender,
                'dateOfBirth' => $record->dateOfBirth?->format('Y-m-d'),
                'motherUuid' => $record->motherUuid,
                'motherName' => $mother ? trim(($mother->identificationNumber ?: $mother->name).($mother->name && $mother->identificationNumber ? " ({$mother->name})" : '')) : null,
                'fatherUuid' => $record->fatherUuid,
                'fatherName' => $father ? trim(($father->identificationNumber ?: $father->name).($father->name && $father->identificationNumber ? " ({$father->name})" : '')) : null,
                'isIdentified' => (bool) $record->isIdentified,
                'status' => $record->status ?: 'active',
                'livestockObtainedMethodId' => $record->livestockObtainedMethodId !== null ? (int) $record->livestockObtainedMethodId : null,
                'obtainedMethodName' => $record->relationLoaded('livestockObtainedMethod') ? $record->getRelation('livestockObtainedMethod')?->name : null,
                'dateFirstEnteredToFarm' => $record->dateFirstEnteredToFarm?->format('Y-m-d'),
                'weightAsOnRegistration' => $record->weightAsOnRegistration !== null ? (string) $record->weightAsOnRegistration : null,
                'primaryColor' => $record->primaryColor,
                'secondaryColor' => $record->secondaryColor,
                'deletedAt' => $record->deleted_at?->toIso8601String(),
            ]);
        }

        if ($resource === 'animal-types') {
            return array_merge($base, [
                'name' => (string) $record->getAttribute('name'),
                'speciesCount' => (int) ($record->getAttribute('species_count') ?? 0),
                'breedsCount' => (int) ($record->getAttribute('breeds_count') ?? 0),
                'livestocksCount' => (int) ($record->getAttribute('livestocks_count') ?? 0),
            ]);
        }

        if ($resource === 'species') {
            return array_merge($base, [
                'name' => (string) $record->getAttribute('name'),
                'livestockTypeId' => $record->getAttribute('livestockTypeId') !== null ? (int) $record->getAttribute('livestockTypeId') : null,
                'livestockTypeName' => $record->relationLoaded('livestockType') ? $record->getRelation('livestockType')?->name : null,
                'livestocksCount' => (int) ($record->getAttribute('livestocks_count') ?? 0),
            ]);
        }

        if ($resource === 'breeds') {
            return array_merge($base, [
                'name' => (string) $record->getAttribute('name'),
                'group' => (string) ($record->getAttribute('group') ?? ''),
                'livestockTypeId' => $record->getAttribute('livestockTypeId') !== null ? (int) $record->getAttribute('livestockTypeId') : null,
                'livestockTypeName' => $record->relationLoaded('livestockType') ? $record->getRelation('livestockType')?->name : null,
                'livestocksCount' => (int) ($record->getAttribute('livestocks_count') ?? 0),
            ]);
        }

        return array_merge($base, [
            'name' => (string) $record->getAttribute('name'),
            'livestocksCount' => (int) ($record->getAttribute('livestocks_count') ?? 0),
        ]);
    }

    private function syncPrimaryIdentifier(AnimalSubject $subject, string $tagValue): void
    {
        if ($tagValue === '') {
            SubjectIdentifier::query()->where('subject_uuid', $subject->uuid)->delete();

            return;
        }

        $identifierType = IdentifierType::query()->first();
        if (! $identifierType) {
            $identifierType = IdentifierType::query()->create([
                'uuid' => (string) Str::uuid(),
                'code' => 'visual_tag',
                'name' => 'Visual Tag',
                'supported_subject_modes' => ['individual', 'group'],
                'normalization_rule' => 'trim_uppercase',
                'uniqueness_scope' => 'global',
                'status' => 'active',
            ]);
        }

        $existing = SubjectIdentifier::query()
            ->where('subject_uuid', $subject->uuid)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            $existing->update([
                'display_value' => $tagValue,
                'normalized_value' => strtoupper(trim($tagValue)),
            ]);

            return;
        }

        SubjectIdentifier::query()->create([
            'uuid' => (string) Str::uuid(),
            'subject_uuid' => $subject->uuid,
            'identifier_type_uuid' => $identifierType->uuid,
            'display_value' => $tagValue,
            'normalized_value' => strtoupper(trim($tagValue)),
            'status' => 'active',
            'assigned_at' => now(),
        ]);
    }

    private function farmUuidOptions(): array
    {
        return Farm::query()
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
            ->all();
    }

    private function dynamicSpeciesOptions(): array
    {
        return AnimalSpecies::query()
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'code'])
            ->map(fn (AnimalSpecies $s): array => [
                'value' => $s->uuid,
                'label' => $s->name,
                'description' => $s->code,
            ])
            ->values()
            ->all();
    }

    private function livestockTypeOptions(): array
    {
        return LivestockType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LivestockType $t): array => [
                'value' => (string) $t->id,
                'label' => $t->name,
            ])
            ->values()
            ->all();
    }

    private function speciesOptions(): array
    {
        return Specie::query()
            ->with('livestockType:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'livestockTypeId'])
            ->map(fn (Specie $s): array => [
                'value' => (string) $s->id,
                'label' => $s->name,
                'description' => $s->livestockType?->name,
                'parent' => $s->livestockTypeId ? (string) $s->livestockTypeId : null,
            ])
            ->values()
            ->all();
    }

    private function breedOptions(): array
    {
        return Breed::query()
            ->with('livestockType:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'group', 'livestockTypeId'])
            ->map(fn (Breed $b): array => [
                'value' => (string) $b->id,
                'label' => $b->name,
                'description' => $b->group ?: $b->livestockType?->name,
                'parent' => $b->livestockTypeId ? (string) $b->livestockTypeId : null,
            ])
            ->values()
            ->all();
    }

    private function stageOptions(): array
    {
        return Stage::query()
            ->with('livestockType:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'livestockTypeId'])
            ->map(fn (Stage $s): array => [
                'value' => (string) $s->id,
                'label' => $s->name,
                'description' => $s->livestockType?->name,
                'parent' => $s->livestockTypeId ? (string) $s->livestockTypeId : null,
            ])
            ->values()
            ->all();
    }

    private function obtainedMethodOptions(): array
    {
        return LivestockObtainedMethod::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LivestockObtainedMethod $m): array => [
                'value' => (string) $m->id,
                'label' => $m->name,
            ])
            ->values()
            ->all();
    }

    private function parentLivestockOptions(string $gender): array
    {
        return Livestock::query()
            ->where('gender', $gender)
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'uuid', 'name', 'identificationNumber'])
            ->map(fn (Livestock $l): array => [
                'value' => $l->uuid,
                'label' => $l->name ?: ($l->identificationNumber ?: 'Livestock #'.$l->id),
                'description' => $l->identificationNumber,
            ])
            ->values()
            ->all();
    }
}
