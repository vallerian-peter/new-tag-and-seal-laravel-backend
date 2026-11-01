<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\District;
use App\Models\Division;
use App\Models\Farm;
use App\Models\Region;
use App\Models\Street;
use App\Models\Village;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationResourceController extends Controller
{
    private const RESOURCES = [
        'countries' => Country::class,
        'regions' => Region::class,
        'districts' => District::class,
        'divisions' => Division::class,
        'wards' => Ward::class,
        'villages' => Village::class,
        'streets' => Street::class,
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->resolveModelClass($resource);
        $perPage = min(max((int) $request->integer('per_page', 10), 5), 100);
        $search = trim((string) $request->query('search', ''));
        $parentId = trim((string) $request->query('parentId', ''));
        $sort = (string) $request->query('sort', 'desc');

        $query = $this->baseQuery($resource, $modelClass);

        if ($parentId !== '' && ctype_digit($parentId)) {
            $this->applyParentFilter($query, $resource, (int) $parentId);
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

        $countries = Country::query()
            ->orderBy('name')
            ->get(['id', 'name', 'shortName'])
            ->map(fn (Country $c): array => [
                'value' => (string) $c->id,
                'label' => $c->name,
                'description' => $c->shortName,
            ])
            ->values()
            ->all();

        $regions = in_array($resource, ['districts', 'divisions', 'wards', 'villages', 'streets'], true)
            ? Region::query()
                ->with('country:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'shortName', 'countryId'])
                ->map(fn (Region $r): array => [
                    'value' => (string) $r->id,
                    'label' => $r->name,
                    'description' => $r->country?->name ?? $r->shortName,
                    'parent' => (string) $r->countryId,
                ])
                ->values()
                ->all()
            : [];

        $districts = in_array($resource, ['divisions', 'wards', 'villages', 'streets'], true)
            ? District::query()
                ->with('region:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'regionId'])
                ->map(fn (District $d): array => [
                    'value' => (string) $d->id,
                    'label' => $d->name,
                    'description' => $d->region?->name,
                    'parent' => (string) $d->regionId,
                ])
                ->values()
                ->all()
            : [];

        $wards = in_array($resource, ['villages', 'streets'], true)
            ? Ward::query()
                ->with('district:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'districtId'])
                ->map(fn (Ward $w): array => [
                    'value' => (string) $w->id,
                    'label' => $w->name,
                    'description' => $w->district?->name,
                    'parent' => (string) $w->districtId,
                ])
                ->values()
                ->all()
            : [];

        $parents = match ($resource) {
            'regions' => $countries,
            'districts' => $regions,
            'divisions', 'wards' => $districts,
            'villages', 'streets' => $wards,
            default => [],
        };

        return response()->json([
            'status' => true,
            'message' => 'Location options fetched successfully.',
            'data' => [
                'countries' => $countries,
                'regions' => $regions,
                'districts' => $districts,
                'wards' => $wards,
                'parents' => $parents,
            ],
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
            abort(404, "Unsupported location resource [{$resource}].");
        }

        return self::RESOURCES[$resource];
    }

    private function singularLabel(string $resource): string
    {
        return match ($resource) {
            'countries' => 'Country',
            'regions' => 'Region',
            'districts' => 'District',
            'divisions' => 'Division',
            'wards' => 'Ward',
            'villages' => 'Village',
            'streets' => 'Street',
            default => 'Location',
        };
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function baseQuery(string $resource, string $modelClass): Builder
    {
        $query = $modelClass::query();

        return match ($resource) {
            'countries' => $query->withCount(['regions', 'farmers', 'farms']),
            'regions' => $query
                ->with(['country:id,name,shortName'])
                ->withCount(['districts', 'farmers', 'farms']),
            'districts' => $query
                ->with(['region:id,name,shortName,countryId', 'region.country:id,name,shortName'])
                ->withCount(['divisions', 'wards', 'farmers', 'farms']),
            'divisions' => $query
                ->with([
                    'district:id,name,regionId',
                    'district.region:id,name,shortName,countryId',
                    'district.region.country:id,name,shortName',
                ]),
            'wards' => $query
                ->with([
                    'district:id,name,regionId',
                    'district.region:id,name,shortName,countryId',
                    'district.region.country:id,name,shortName',
                ])
                ->withCount(['villages', 'streets', 'farmers', 'farms']),
            'villages' => $query
                ->with([
                    'ward:id,name,districtId',
                    'ward.district:id,name,regionId',
                    'ward.district.region:id,name,shortName,countryId',
                    'ward.district.region.country:id,name,shortName',
                ])
                ->withCount(['farmers', 'farms']),
            'streets' => $query
                ->with([
                    'ward:id,name,districtId',
                    'ward.district:id,name,regionId',
                    'ward.district.region:id,name,shortName,countryId',
                    'ward.district.region.country:id,name,shortName',
                ]),
            default => $query,
        };
    }

    private function applyParentFilter(Builder $query, string $resource, int $parentId): void
    {
        match ($resource) {
            'regions' => $query->where('countryId', $parentId),
            'districts' => $query->where('regionId', $parentId),
            'divisions', 'wards' => $query->where('districtId', $parentId),
            'villages', 'streets' => $query->where('wardId', $parentId),
            default => null,
        };
    }

    private function applySearch(Builder $query, string $resource, string $search): void
    {
        $like = "%{$search}%";

        $query->where(function (Builder $q) use ($resource, $like): void {
            $q->where('name', 'like', $like);

            if (in_array($resource, ['countries', 'regions'], true)) {
                $q->orWhere('shortName', 'like', $like);
            }

            if ($resource === 'regions') {
                $q->orWhereHas('country', fn (Builder $cq) => $cq->where('name', 'like', $like)->orWhere('shortName', 'like', $like));
            } elseif ($resource === 'districts') {
                $q->orWhereHas('region', function (Builder $rq) use ($like): void {
                    $rq->where('name', 'like', $like)
                        ->orWhereHas('country', fn (Builder $cq) => $cq->where('name', 'like', $like));
                });
            } elseif (in_array($resource, ['divisions', 'wards'], true)) {
                $q->orWhereHas('district', function (Builder $dq) use ($like): void {
                    $dq->where('name', 'like', $like)
                        ->orWhereHas('region', fn (Builder $rq) => $rq->where('name', 'like', $like));
                });
            } elseif (in_array($resource, ['villages', 'streets'], true)) {
                $q->orWhereHas('ward', function (Builder $wq) use ($like): void {
                    $wq->where('name', 'like', $like)
                        ->orWhereHas('district', fn (Builder $dq) => $dq->where('name', 'like', $like));
                });
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
            'countries' => [
                ['label' => 'Total countries', 'value' => Country::query()->count(), 'note' => 'Registered national jurisdictions'],
                ['label' => 'Linked regions', 'value' => Region::query()->count(), 'note' => 'Administrative regions mapped to countries'],
                ['label' => 'Registered farms', 'value' => Farm::query()->count(), 'note' => 'Farms operating across countries'],
            ],
            'regions' => [
                ['label' => 'Total regions', 'value' => Region::query()->count(), 'note' => 'First-level administrative regions'],
                ['label' => 'Countries covered', 'value' => Region::query()->distinct('countryId')->count('countryId'), 'note' => 'Countries with registered regions'],
                ['label' => 'Linked districts', 'value' => District::query()->count(), 'note' => 'Districts mapped under regions'],
            ],
            'districts' => [
                ['label' => 'Total districts', 'value' => District::query()->count(), 'note' => 'Districts and councils registered'],
                ['label' => 'Regions covered', 'value' => District::query()->distinct('regionId')->count('regionId'), 'note' => 'Regions with active districts'],
                ['label' => 'Linked wards', 'value' => Ward::query()->count(), 'note' => 'Wards mapped under districts'],
            ],
            'divisions' => [
                ['label' => 'Total divisions', 'value' => Division::query()->count(), 'note' => 'Administrative divisions under districts'],
                ['label' => 'Districts covered', 'value' => Division::query()->distinct('districtId')->count('districtId'), 'note' => 'Districts with registered divisions'],
                ['label' => 'Available districts', 'value' => District::query()->count(), 'note' => 'Total parent districts in catalog'],
            ],
            'wards' => [
                ['label' => 'Total wards', 'value' => Ward::query()->count(), 'note' => 'Administrative wards under districts'],
                ['label' => 'Linked villages', 'value' => Village::query()->count(), 'note' => 'Villages mapped under wards'],
                ['label' => 'Linked streets', 'value' => Street::query()->count(), 'note' => 'Streets mapped under wards'],
            ],
            'villages' => [
                ['label' => 'Total villages', 'value' => Village::query()->count(), 'note' => 'Rural and peri-urban villages'],
                ['label' => 'Wards covered', 'value' => Village::query()->distinct('wardId')->count('wardId'), 'note' => 'Wards with registered villages'],
                ['label' => 'Linked farms', 'value' => Farm::query()->whereNotNull('villageId')->count(), 'note' => 'Farms assigned to a village'],
            ],
            'streets' => [
                ['label' => 'Total streets', 'value' => Street::query()->count(), 'note' => 'Streets and sub-localities under wards'],
                ['label' => 'Wards covered', 'value' => Street::query()->distinct('wardId')->count('wardId'), 'note' => 'Wards with registered streets'],
                ['label' => 'Available wards', 'value' => Ward::query()->count(), 'note' => 'Total parent wards in catalog'],
            ],
            default => [],
        };
    }

    private function rules(string $resource, ?Model $record = null, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return match ($resource) {
            'countries' => [
                'name' => [$req, 'string', 'max:255', Rule::unique('countries', 'name')->ignore($record?->getKey())],
                'shortName' => [$req, 'string', 'max:32', Rule::unique('countries', 'shortName')->ignore($record?->getKey())],
            ],
            'regions' => [
                'name' => [$req, 'string', 'max:255'],
                'shortName' => [$req, 'string', 'max:32'],
                'countryId' => [$req, 'integer', 'exists:countries,id'],
            ],
            'districts' => [
                'name' => [$req, 'string', 'max:255'],
                'regionId' => [$req, 'integer', 'exists:regions,id'],
            ],
            'divisions' => [
                'name' => [$req, 'string', 'max:255'],
                'districtId' => [$req, 'integer', 'exists:districts,id'],
            ],
            'wards' => [
                'name' => [$req, 'string', 'max:255'],
                'districtId' => [$req, 'integer', 'exists:districts,id'],
            ],
            'villages' => [
                'name' => [$req, 'string', 'max:255'],
                'wardId' => [$req, 'integer', 'exists:wards,id'],
            ],
            'streets' => [
                'name' => [$req, 'string', 'max:255'],
                'wardId' => [$req, 'integer', 'exists:wards,id'],
            ],
            default => [],
        };
    }

    private function present(string $resource, Model $record): array
    {
        $base = [
            'id' => (int) $record->getKey(),
            'name' => (string) $record->getAttribute('name'),
            'displayName' => (string) $record->getAttribute('name'),
            'createdAt' => $record->getAttribute('created_at')?->toIso8601String(),
            'updatedAt' => $record->getAttribute('updated_at')?->toIso8601String(),
        ];

        return match ($resource) {
            'countries' => array_merge($base, [
                'shortName' => $record->getAttribute('shortName'),
                'regionsCount' => (int) ($record->getAttribute('regions_count') ?? 0),
                'farmersCount' => (int) ($record->getAttribute('farmers_count') ?? 0),
                'farmsCount' => (int) ($record->getAttribute('farms_count') ?? 0),
            ]),
            'regions' => array_merge($base, [
                'shortName' => $record->getAttribute('shortName'),
                'countryId' => $record->getAttribute('countryId') !== null ? (int) $record->getAttribute('countryId') : null,
                'countryName' => $record->relationLoaded('country') ? $record->getRelation('country')?->name : null,
                'countryShortName' => $record->relationLoaded('country') ? $record->getRelation('country')?->shortName : null,
                'districtsCount' => (int) ($record->getAttribute('districts_count') ?? 0),
                'farmersCount' => (int) ($record->getAttribute('farmers_count') ?? 0),
                'farmsCount' => (int) ($record->getAttribute('farms_count') ?? 0),
            ]),
            'districts' => array_merge($base, [
                'regionId' => $record->getAttribute('regionId') !== null ? (int) $record->getAttribute('regionId') : null,
                'regionName' => $record->relationLoaded('region') ? $record->getRelation('region')?->name : null,
                'countryId' => $record->relationLoaded('region') && $record->getRelation('region')?->countryId !== null
                    ? (int) $record->getRelation('region')->countryId
                    : null,
                'countryName' => $record->relationLoaded('region') && $record->getRelation('region')?->relationLoaded('country')
                    ? $record->getRelation('region')->getRelation('country')?->name
                    : null,
                'divisionsCount' => (int) ($record->getAttribute('divisions_count') ?? 0),
                'wardsCount' => (int) ($record->getAttribute('wards_count') ?? 0),
                'farmersCount' => (int) ($record->getAttribute('farmers_count') ?? 0),
                'farmsCount' => (int) ($record->getAttribute('farms_count') ?? 0),
            ]),
            'divisions' => array_merge($base, [
                'districtId' => $record->getAttribute('districtId') !== null ? (int) $record->getAttribute('districtId') : null,
                'districtName' => $record->relationLoaded('district') ? $record->getRelation('district')?->name : null,
                'regionId' => $record->relationLoaded('district') && $record->getRelation('district')?->regionId !== null
                    ? (int) $record->getRelation('district')->regionId
                    : null,
                'regionName' => $record->relationLoaded('district') && $record->getRelation('district')?->relationLoaded('region')
                    ? $record->getRelation('district')->getRelation('region')?->name
                    : null,
                'countryId' => $record->relationLoaded('district')
                    && $record->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('district')->getRelation('region')?->countryId !== null
                    ? (int) $record->getRelation('district')->getRelation('region')->countryId
                    : null,
                'countryName' => $record->relationLoaded('district')
                    && $record->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('district')->getRelation('region')?->relationLoaded('country')
                    ? $record->getRelation('district')->getRelation('region')->getRelation('country')?->name
                    : null,
            ]),
            'wards' => array_merge($base, [
                'districtId' => $record->getAttribute('districtId') !== null ? (int) $record->getAttribute('districtId') : null,
                'districtName' => $record->relationLoaded('district') ? $record->getRelation('district')?->name : null,
                'regionId' => $record->relationLoaded('district') && $record->getRelation('district')?->regionId !== null
                    ? (int) $record->getRelation('district')->regionId
                    : null,
                'regionName' => $record->relationLoaded('district') && $record->getRelation('district')?->relationLoaded('region')
                    ? $record->getRelation('district')->getRelation('region')?->name
                    : null,
                'countryId' => $record->relationLoaded('district')
                    && $record->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('district')->getRelation('region')?->countryId !== null
                    ? (int) $record->getRelation('district')->getRelation('region')->countryId
                    : null,
                'countryName' => $record->relationLoaded('district')
                    && $record->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('district')->getRelation('region')?->relationLoaded('country')
                    ? $record->getRelation('district')->getRelation('region')->getRelation('country')?->name
                    : null,
                'villagesCount' => (int) ($record->getAttribute('villages_count') ?? 0),
                'streetsCount' => (int) ($record->getAttribute('streets_count') ?? 0),
                'farmersCount' => (int) ($record->getAttribute('farmers_count') ?? 0),
                'farmsCount' => (int) ($record->getAttribute('farms_count') ?? 0),
            ]),
            'villages' => array_merge($base, [
                'wardId' => $record->getAttribute('wardId') !== null ? (int) $record->getAttribute('wardId') : null,
                'wardName' => $record->relationLoaded('ward') ? $record->getRelation('ward')?->name : null,
                'districtId' => $record->relationLoaded('ward') && $record->getRelation('ward')?->districtId !== null
                    ? (int) $record->getRelation('ward')->districtId
                    : null,
                'districtName' => $record->relationLoaded('ward') && $record->getRelation('ward')?->relationLoaded('district')
                    ? $record->getRelation('ward')->getRelation('district')?->name
                    : null,
                'regionId' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->regionId !== null
                    ? (int) $record->getRelation('ward')->getRelation('district')->regionId
                    : null,
                'regionName' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->relationLoaded('region')
                    ? $record->getRelation('ward')->getRelation('district')->getRelation('region')?->name
                    : null,
                'countryId' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('ward')->getRelation('district')->getRelation('region')?->countryId !== null
                    ? (int) $record->getRelation('ward')->getRelation('district')->getRelation('region')->countryId
                    : null,
                'countryName' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('ward')->getRelation('district')->getRelation('region')?->relationLoaded('country')
                    ? $record->getRelation('ward')->getRelation('district')->getRelation('region')->getRelation('country')?->name
                    : null,
                'farmersCount' => (int) ($record->getAttribute('farmers_count') ?? 0),
                'farmsCount' => (int) ($record->getAttribute('farms_count') ?? 0),
            ]),
            'streets' => array_merge($base, [
                'wardId' => $record->getAttribute('wardId') !== null ? (int) $record->getAttribute('wardId') : null,
                'wardName' => $record->relationLoaded('ward') ? $record->getRelation('ward')?->name : null,
                'districtId' => $record->relationLoaded('ward') && $record->getRelation('ward')?->districtId !== null
                    ? (int) $record->getRelation('ward')->districtId
                    : null,
                'districtName' => $record->relationLoaded('ward') && $record->getRelation('ward')?->relationLoaded('district')
                    ? $record->getRelation('ward')->getRelation('district')?->name
                    : null,
                'regionId' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->regionId !== null
                    ? (int) $record->getRelation('ward')->getRelation('district')->regionId
                    : null,
                'regionName' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->relationLoaded('region')
                    ? $record->getRelation('ward')->getRelation('district')->getRelation('region')?->name
                    : null,
                'countryId' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('ward')->getRelation('district')->getRelation('region')?->countryId !== null
                    ? (int) $record->getRelation('ward')->getRelation('district')->getRelation('region')->countryId
                    : null,
                'countryName' => $record->relationLoaded('ward')
                    && $record->getRelation('ward')?->relationLoaded('district')
                    && $record->getRelation('ward')->getRelation('district')?->relationLoaded('region')
                    && $record->getRelation('ward')->getRelation('district')->getRelation('region')?->relationLoaded('country')
                    ? $record->getRelation('ward')->getRelation('district')->getRelation('region')->getRelation('country')?->name
                    : null,
            ]),
            default => $base,
        };
    }
}
