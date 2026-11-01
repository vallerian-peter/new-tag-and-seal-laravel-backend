<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ExtensionOfficer;
use App\Models\ExtensionOfficerFarmInvite;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\FarmUser;
use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PeopleResourceController extends Controller
{
    private const RESOURCES = [
        'farms' => Farm::class,
        'farmers' => Farmer::class,
        'extension-officers' => ExtensionOfficer::class,
        'farm-users' => FarmUser::class,
        'farm-invites' => ExtensionOfficerFarmInvite::class,
        'system-user-profiles' => SystemUser::class,
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $model = $this->model($resource);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'tab' => ['nullable', Rule::in(['all', 'deleted'])],
            'sort' => ['nullable', Rule::in(['desc', 'asc', 'alpha-asc', 'alpha-desc', 'created_at', 'name', 'firstName', 'access_code'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $tab = $validated['tab'] ?? 'all';
        $deletedMap = $this->softDeletedMap($resource);
        $deletedIds = $deletedMap->keys()->map(fn ($id) => (int) $id)->values();

        $query = $model::query();
        $this->eagerLoad($query, $resource);
        if (in_array($resource, ['farms', 'farmers'], true)) {
            if ($tab === 'deleted') {
                $query->whereIn('id', $deletedIds->isEmpty() ? [-1] : $deletedIds->all());
            } elseif ($deletedIds->isNotEmpty()) {
                $query->whereNotIn('id', $deletedIds->all());
            }
        }
        $this->search($query, $resource, trim((string) ($validated['search'] ?? '')));
        if (! empty($validated['status'])) {
            if (in_array($resource, ['farms', 'farmers', 'farm-users', 'system-user-profiles'], true)) {
                $query->where('status', $validated['status']);
            } elseif ($resource === 'extension-officers' && in_array($validated['status'], ['verified', 'unverified'], true)) {
                $query->where('isVerified', $validated['status'] === 'verified');
            }
        }
        $this->applySort($query, $resource, $validated['sort'] ?? 'desc', $validated['direction'] ?? null);
        $paginator = $query->paginate($validated['per_page'] ?? 10)->withQueryString();
        $items = collect($paginator->items());

        return response()->json([
            'status' => true,
            'data' => $this->present($items, $resource, $deletedMap),
            'meta' => [
                'currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(), 'total' => $paginator->total(),
                'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(),
                'deletedCount' => $deletedIds->count(),
                'stats' => $this->stats($resource, $model, $deletedIds),
            ],
        ]);
    }

    public function show(string $resource, int $record): JsonResponse
    {
        $model = $this->model($resource);
        $query = $model::query();
        $this->eagerLoad($query, $resource);
        $item = $query->findOrFail($record);

        return response()->json(['status' => true, 'data' => $this->present(collect([$item]), $resource, $this->softDeletedMap($resource))->first()]);
    }

    public function options(string $resource): JsonResponse
    {
        $this->model($resource);
        $data = match ($resource) {
            'farms' => [
                'farmers' => $this->peopleOptions('farmers', 'surname', 'phone1'),
                ...$this->locationOptions(),
                'legalStatuses' => $this->simpleOptions('legal_statuses'),
            ],
            'farmers' => [
                ...$this->locationOptions(true),
                'identityCardTypes' => $this->simpleOptions('identity_card_types'),
                'schoolLevels' => $this->simpleOptions('school_levels'),
                'creators' => $this->userOptions(),
            ],
            'extension-officers' => $this->locationOptions(),
            'farm-users' => ['farms' => $this->farmOptions()],
            'farm-invites' => ['extensionOfficers' => $this->peopleOptions('extension_officers', 'lastName', 'phone'), 'farms' => $this->farmOptions(true)],
            'system-user-profiles' => ['creators' => $this->userOptions()],
        };

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $modelClass = $this->model($resource);
        $data = $this->validated($request, $resource);
        $record = DB::transaction(function () use ($request, $resource, $modelClass, $data): Model {
            $password = $data['password'] ?? null;
            $username = $data['username'] ?? null;
            $accountEmail = $data['email'] ?? null;
            $accountRole = $data['accountRole'] ?? null;
            unset($data['password'], $data['username'], $data['accountRole'], $data['uuid'], $data['farmerNo'], $data['createdBy']);
            if ($resource === 'system-user-profiles') {
                unset($data['email']);
            }
            if ($resource === 'farms') {
                $data['uuid'] = (string) Str::uuid();
                $data['size'] = $data['size'] ?? 0;
                $data['sizeUnit'] = $data['sizeUnit'] ?? 'acre';
                $data['status'] = $data['status'] ?? 'active';
            }
            if ($resource === 'farmers') {
                $data['farmerNo'] = $this->generateFarmerNumber();
                $data['status'] = $data['status'] ?? 'active';
                $data['createdBy'] = $request->user()->id;
                $data = $this->normalizeFarmerPayload($data);
            }
            if ($resource === 'farm-users') {
                $data['uuid'] = (string) Str::uuid();
                $data['farmUuid'] = $this->normalizeFarmUuid($data['farmUuid']);
                $data['status'] = $data['status'] ?? 'active';
            }
            if ($resource === 'extension-officers') {
                $data['password'] = Hash::make($password ?: 'password');
                $data['isVerified'] = (bool) ($data['isVerified'] ?? false);
            }
            if ($resource === 'farm-invites') {
                $data = $this->inviteData($data);
            }
            if ($resource === 'system-user-profiles') {
                $data['status'] = $data['status'] ?? 'active';
                $data['createdBy'] = $request->user()->id;
            }
            $record = $modelClass::create($data);
            if (in_array($resource, ['farmers', 'extension-officers', 'farm-users', 'system-user-profiles'], true)) {
                $this->ensureLinkedUserAccount($resource, $record, $username, $accountEmail, $password, $request->user()->id, $accountRole);
            }

            return $record;
        });

        return response()->json(['status' => true, 'message' => $this->label($resource).' created successfully.', 'data' => $this->freshPresented($record, $resource)], 201);
    }

    public function update(Request $request, string $resource, int $record): JsonResponse
    {
        if ($request->boolean('restore') || $request->input('action') === 'restore') {
            return $this->restore($request, $resource, $record);
        }

        $modelClass = $this->model($resource);
        $item = $modelClass::findOrFail($record);
        $data = $this->validated($request, $resource, $item);
        DB::transaction(function () use ($request, $resource, $item, $data): void {
            $password = $data['password'] ?? null;
            $username = $data['username'] ?? null;
            $accountEmail = $data['email'] ?? null;
            $accountRole = $data['accountRole'] ?? null;
            unset($data['password'], $data['username'], $data['accountRole'], $data['uuid'], $data['farmerNo'], $data['createdBy']);
            if ($resource === 'system-user-profiles') {
                unset($data['email']);
            }
            if ($resource === 'farmers') {
                $data = $this->normalizeFarmerPayload($data);
            }
            if ($resource === 'farm-users' && isset($data['farmUuid'])) {
                $data['farmUuid'] = $this->normalizeFarmUuid($data['farmUuid']);
            }
            if ($resource === 'extension-officers' && filled($password)) {
                $data['password'] = Hash::make($password);
            }
            if ($resource === 'farm-invites') {
                $data = $this->inviteData($data, $item);
            }
            $item->update($data);
            $this->syncLinkedAccount($resource, $item, $username, $accountEmail, $password, $request->user()->id, $accountRole);
        });

        return response()->json(['status' => true, 'message' => $this->label($resource).' updated successfully.', 'data' => $this->freshPresented($item, $resource)]);
    }

    public function restore(Request $request, string $resource, int $record): JsonResponse
    {
        if (! in_array($resource, ['farms', 'farmers'], true)) {
            return response()->json(['status' => false, 'message' => 'Restore is only supported for farms and farmers.'], 405);
        }

        $modelClass = $this->model($resource);
        $item = $modelClass::findOrFail($record);

        DB::transaction(function () use ($request, $resource, $item): void {
            $restoredStatus = 'active';
            if (Schema::hasTable('platform_change_journal')) {
                $entry = DB::table('platform_change_journal')
                    ->where('entity_type', $this->softDeleteEntityType($resource))
                    ->where('legacy_key', (string) $item->id)
                    ->whereNull('replayed_at')
                    ->first();

                if ($entry && filled($entry->payload_json)) {
                    $payload = json_decode((string) $entry->payload_json, true);
                    if (is_array($payload) && ! empty($payload['previous_status'])) {
                        $restoredStatus = (string) $payload['previous_status'];
                    }
                }

                DB::table('platform_change_journal')
                    ->where('entity_type', $this->softDeleteEntityType($resource))
                    ->where('legacy_key', (string) $item->id)
                    ->delete();
            }

            $item->update(['status' => $restoredStatus]);
            if ($resource === 'farmers') {
                User::query()
                    ->where('role', UserRole::FARMER)
                    ->where('roleId', $item->id)
                    ->update([
                        'status' => $restoredStatus === 'notActive' ? UserStatus::NOT_ACTIVE : UserStatus::ACTIVE,
                        'updatedBy' => $request->user()->id,
                    ]);
            }
        });

        return response()->json([
            'status' => true,
            'message' => $this->label($resource).' restored successfully.',
            'data' => $this->freshPresented($item, $resource),
        ]);
    }

    public function destroy(Request $request, string $resource, int $record): JsonResponse
    {
        $modelClass = $this->model($resource);
        $item = $modelClass::findOrFail($record);

        if (in_array($resource, ['farms', 'farmers'], true)) {
            $force = $request->boolean('force');

            if (! $force) {
                DB::transaction(function () use ($request, $resource, $item): void {
                    if (Schema::hasTable('platform_change_journal')) {
                        DB::table('platform_change_journal')
                            ->where('entity_type', $this->softDeleteEntityType($resource))
                            ->where('legacy_key', (string) $item->id)
                            ->delete();

                        DB::table('platform_change_journal')->insert([
                            'uuid' => (string) Str::uuid(),
                            'entity_type' => $this->softDeleteEntityType($resource),
                            'legacy_key' => (string) $item->id,
                            'operation' => 'soft_deleted',
                            'payload_json' => json_encode([
                                'previous_status' => $item->status,
                                'deleted_by' => $request->user()->id,
                                'deleted_at' => now()->toIso8601String(),
                            ]),
                            'captured_at' => now(),
                            'replayed_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $inactiveStatus = $resource === 'farms' ? 'not-active' : 'notActive';
                    $item->update(['status' => $inactiveStatus]);

                    if ($resource === 'farmers') {
                        User::query()
                            ->where('role', UserRole::FARMER)
                            ->where('roleId', $item->id)
                            ->update([
                                'status' => UserStatus::NOT_ACTIVE,
                                'updatedBy' => $request->user()->id,
                            ]);
                    }
                });

                return response()->json([
                    'status' => true,
                    'message' => $this->label($resource).' moved to Deleted tab.',
                ]);
            }

            DB::transaction(function () use ($resource, $item): void {
                if (Schema::hasTable('platform_change_journal')) {
                    DB::table('platform_change_journal')
                        ->where('entity_type', $this->softDeleteEntityType($resource))
                        ->where('legacy_key', (string) $item->id)
                        ->delete();
                }

                if ($resource === 'farmers') {
                    $farmIds = Farm::query()->where('farmerId', $item->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
                    if ($farmIds !== [] && Schema::hasTable('platform_change_journal')) {
                        DB::table('platform_change_journal')
                            ->where('entity_type', $this->softDeleteEntityType('farms'))
                            ->whereIn('legacy_key', $farmIds)
                            ->delete();
                    }
                    User::query()->where('role', UserRole::FARMER)->where('roleId', $item->id)->delete();
                }

                $item->delete();
            });

            return response()->json([
                'status' => true,
                'message' => $this->label($resource).' permanently deleted.',
            ]);
        }

        DB::transaction(function () use ($resource, $item): void {
            if ($resource === 'farm-users') {
                User::query()->where('role', UserRole::FARM_INVITED_USER)->where('roleId', $item->id)->delete();
            }
            if ($resource === 'extension-officers') {
                User::query()->where('role', UserRole::EXTENSION_OFFICER)->where('roleId', $item->id)->delete();
            }
            if ($resource === 'system-user-profiles') {
                $linkedAdmins = User::query()->where('role', UserRole::SYSTEM_USER)->where('roleId', $item->id)->count();
                if ($linkedAdmins > 0 && User::query()->where('role', UserRole::SYSTEM_USER)->count() <= 1) {
                    throw ValidationException::withMessages(['record' => 'The final administrator profile cannot be deleted.']);
                }
                User::query()->where('roleId', $item->id)->whereIn('role', [UserRole::SYSTEM_USER, UserRole::VET])->delete();
            }
            $item->delete();
        });

        return response()->json(['status' => true, 'message' => $this->label($resource).' deleted successfully.']);
    }

    private function validated(Request $request, string $resource, ?Model $record = null): array
    {
        $sometimes = $record ? 'sometimes' : 'required';
        $rules = match ($resource) {
            'farms' => [
                'farmerId' => [$sometimes, 'integer', Rule::exists('farmers', 'id')], 'referenceNo' => [$sometimes, 'string', 'max:255', Rule::unique('farms', 'referenceNo')->ignore($record?->id)],
                'regionalRegNo' => ['nullable', 'string', 'max:255'], 'name' => [$sometimes, 'string', 'max:255'], 'size' => ['nullable', 'numeric', 'min:0'],
                'sizeUnit' => [$sometimes, Rule::in(['acre', 'hectare', 'square_meter', 'square_kilometer'])], 'latitudes' => ['nullable', 'numeric', 'between:-90,90'], 'longitudes' => ['nullable', 'numeric', 'between:-180,180'],
                'physicalAddress' => [$sometimes, 'string', 'max:500'], 'villageId' => ['nullable', 'integer', Rule::exists('villages', 'id')], 'wardId' => ['nullable', 'integer', Rule::exists('wards', 'id')],
                'districtId' => ['nullable', 'integer', Rule::exists('districts', 'id')], 'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')], 'countryId' => [$sometimes, 'integer', Rule::exists('countries', 'id')],
                'legalStatusId' => ['nullable', 'integer', Rule::exists('legal_statuses', 'id')], 'status' => [$sometimes, Rule::in(['active', 'not-active'])],
            ],
            'farmers' => [
                'firstName' => [$sometimes, 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'surname' => [$sometimes, 'string', 'max:255'],
                'phone1' => [$sometimes, 'string', 'max:40'], 'phone2' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:255'], 'physicalAddress' => ['nullable', 'string', 'max:500'], 'farmerOrganizationMembership' => ['nullable', 'string', 'max:255'],
                'dateOfBirth' => ['nullable', 'date', 'before:today'], 'gender' => [$sometimes, Rule::in(['male', 'female'])], 'identityCardTypeId' => ['nullable', 'integer', Rule::exists('identity_card_types', 'id')], 'identityNumber' => ['nullable', 'string', 'max:255'],
                'streetId' => ['nullable', 'integer', Rule::exists('streets', 'id')], 'schoolLevelId' => ['nullable', 'integer', Rule::exists('school_levels', 'id')], 'villageId' => ['nullable', 'integer', Rule::exists('villages', 'id')], 'wardId' => ['nullable', 'integer', Rule::exists('wards', 'id')],
                'districtId' => ['nullable', 'integer', Rule::exists('districts', 'id')], 'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')], 'countryId' => [$sometimes, 'integer', Rule::exists('countries', 'id')], 'farmerType' => [$sometimes, Rule::in(['individual', 'organization'])],
                'createdBy' => ['nullable', 'integer', Rule::exists('users', 'id')], 'status' => [$sometimes, Rule::in(['active', 'notActive'])], 'username' => ['nullable', 'string', 'max:255'], 'password' => ['nullable', 'string', 'min:6', 'max:255'],
            ],
            'extension-officers' => [
                'firstName' => [$sometimes, 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => [$sometimes, 'string', 'max:255'],
                'email' => [$sometimes, 'email', 'max:255', Rule::unique('extension_officers', 'email')->ignore($record?->id)], 'phone' => [$sometimes, 'string', 'max:40'], 'username' => ['nullable', 'string', 'max:255'], 'password' => [$record ? 'nullable' : 'sometimes', 'string', 'min:6', 'max:255'],
                'gender' => [$sometimes, Rule::in(['male', 'female'])], 'licenseNumber' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:500'], 'countryId' => [$sometimes, 'integer', Rule::exists('countries', 'id')],
                'regionId' => [$sometimes, 'integer', Rule::exists('regions', 'id')], 'districtId' => [$sometimes, 'integer', Rule::exists('districts', 'id')], 'wardId' => [$sometimes, 'integer', Rule::exists('wards', 'id')], 'organization' => ['nullable', 'string', 'max:255'],
                'isVerified' => ['sometimes', 'boolean'], 'specialization' => ['nullable', 'string', 'max:100'],
            ],
            'farm-users' => [
                'farmUuid' => [$sometimes, 'string', Rule::exists('farms', 'uuid')], 'roleTitle' => [$sometimes, 'string', 'max:80'], 'firstName' => [$sometimes, 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => [$sometimes, 'string', 'max:255'],
                'phone' => [$sometimes, 'string', 'max:40'], 'email' => [$sometimes, 'email', 'max:255', Rule::unique('farm_users', 'email')->ignore($record?->id)], 'gender' => [$sometimes, Rule::in(['male', 'female'])], 'status' => [$record ? 'sometimes' : 'nullable', Rule::in(['active', 'notActive'])], 'username' => ['nullable', 'string', 'max:255'], 'password' => ['nullable', 'string', 'min:6', 'max:255'],
            ],
            'farm-invites' => ['extensionOfficerId' => [$sometimes, 'integer', Rule::exists('extension_officers', 'id')], 'farmId' => [$sometimes, 'integer', Rule::exists('farms', 'id')], 'access_code' => ['nullable', 'string', 'max:255', Rule::unique('extension_officer_farm_invites', 'access_code')->ignore($record?->id)]],
            'system-user-profiles' => ['firstName' => [$sometimes, 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => [$sometimes, 'string', 'max:255'], 'phone' => [$sometimes, 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:500'], 'status' => [$record ? 'sometimes' : 'nullable', Rule::in(['active', 'notActive'])], 'createdBy' => ['nullable', 'integer', Rule::exists('users', 'id')], 'username' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'accountRole' => ['nullable', Rule::in([UserRole::SYSTEM_USER, UserRole::VET])], 'password' => ['nullable', 'string', 'min:6', 'max:255']],
        };

        return $request->validate($rules);
    }

    private function model(string $resource): string
    {
        return self::RESOURCES[$resource] ?? throw ValidationException::withMessages(['resource' => 'Unsupported People & Organization resource.']);
    }

    private function eagerLoad(Builder $query, string $resource): void
    {
        match ($resource) {
            'farms' => $query->with([
                'farmer:id,firstName,middleName,surname',
                'country:id,name',
                'region:id,name',
                'district:id,name',
                'ward:id,name',
                'village:id,name',
                'legalStatus:id,name',
            ]),
            'farmers' => $query->with([
                'country:id,name',
                'region:id,name',
                'district:id,name',
                'ward:id,name',
                'village:id,name',
                'identityCardType:id,name',
                'schoolLevel:id,name',
                'creator:id,username,email',
            ]),
            'extension-officers' => $query->with(['country:id,name', 'region:id,name', 'district:id,name', 'ward:id,name'])->withCount('farmInvites'),
            'farm-invites' => $query->with(['extensionOfficer:id,firstName,middleName,lastName', 'farmer:id,firstName,middleName,surname,email']),
            'system-user-profiles' => $query->with('creator:id,username,email'),
            default => null,
        };
    }

    private function search(Builder $query, string $resource, string $search): void
    {
        if ($search === '') {
            return;
        }
        $like = '%'.$search.'%';
        $fields = match ($resource) {
            'farms' => ['name', 'referenceNo', 'regionalRegNo', 'physicalAddress'],
            'farmers' => ['farmerNo', 'firstName', 'middleName', 'surname', 'phone1', 'email'],
            'extension-officers' => ['firstName', 'middleName', 'lastName', 'email', 'phone', 'organization', 'specialization'],
            'farm-users' => ['firstName', 'middleName', 'lastName', 'email', 'phone', 'roleTitle'],
            'farm-invites' => ['access_code'],
            'system-user-profiles' => ['firstName', 'middleName', 'lastName', 'phone', 'address'],
        };
        $query->where(function (Builder $builder) use ($fields, $like, $resource): void {
            foreach ($fields as $index => $field) {
                $index === 0 ? $builder->where($field, 'like', $like) : $builder->orWhere($field, 'like', $like);
            }
            if ($resource === 'farms') {
                $builder->orWhereHas('farmer', fn ($farmer) => $farmer->where('firstName', 'like', $like)->orWhere('surname', 'like', $like));
            }
            if ($resource === 'farm-invites') {
                $builder->orWhereHas('extensionOfficer', fn ($officer) => $officer->where('firstName', 'like', $like)->orWhere('lastName', 'like', $like))
                    ->orWhereHas('farmer', fn ($farmer) => $farmer->where('firstName', 'like', $like)->orWhere('surname', 'like', $like)->orWhere('email', 'like', $like));
            }
            if ($resource === 'system-user-profiles') {
                $builder->orWhereExists(function ($sub) use ($like): void {
                    $sub->selectRaw('1')
                        ->from('users')
                        ->whereColumn('users.roleId', 'system_users.id')
                        ->whereIn('users.role', [UserRole::SYSTEM_USER, UserRole::VET])
                        ->where(function ($userQuery) use ($like): void {
                            $userQuery->where('users.username', 'like', $like)
                                ->orWhere('users.email', 'like', $like);
                        });
                });
            }
        });
    }

    private function applySort(Builder $query, string $resource, string $sort, ?string $direction): void
    {
        $alphaColumn = match ($resource) {
            'farms' => 'name',
            'farm-invites' => 'access_code',
            default => 'firstName',
        };

        match ($sort) {
            'asc' => $query->orderBy('created_at')->orderBy('id'),
            'alpha-asc' => $query->orderBy($alphaColumn)->orderByDesc('id'),
            'alpha-desc' => $query->orderByDesc($alphaColumn)->orderByDesc('id'),
            'name', 'firstName', 'access_code', 'created_at' => $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    private function present(Collection $items, string $resource, ?Collection $deletedMap = null): Collection
    {
        $deletedMap ??= collect();
        $farmMap = collect();
        $inviteFarmMap = collect();
        $streetMap = collect();

        if ($resource === 'farm-users') {
            $uuids = $items->flatMap(fn (FarmUser $user) => $user->getFarmUuidsArray())->unique();
            $farmMap = Farm::query()->whereIn('uuid', $uuids)->pluck('name', 'uuid');
        }

        if ($resource === 'farm-invites') {
            $farmerIds = $items->pluck('farmerId')->filter()->unique();
            $inviteFarmMap = Farm::query()
                ->whereIn('farmerId', $farmerIds)
                ->orderBy('id')
                ->get(['id', 'name', 'farmerId'])
                ->unique('farmerId')
                ->keyBy('farmerId');
        }

        if ($resource === 'farmers' && $items->isNotEmpty() && Schema::hasTable('streets')) {
            $streetIds = $items->map(function (Model $farmer) {
                $raw = $farmer->getAttribute('streetId') ?? $farmer->getAttribute('street');

                return is_numeric($raw) ? (int) $raw : null;
            })->filter()->unique()->values();

            if ($streetIds->isNotEmpty()) {
                $streetMap = DB::table('streets')->whereIn('id', $streetIds)->pluck('name', 'id');
            }
        }

        $linkedUsers = collect();
        if (in_array($resource, ['farmers', 'extension-officers', 'farm-users', 'system-user-profiles'], true) && $items->isNotEmpty()) {
            $roles = match ($resource) {
                'farmers' => [UserRole::FARMER],
                'extension-officers' => [UserRole::EXTENSION_OFFICER],
                'farm-users' => [UserRole::FARM_INVITED_USER],
                'system-user-profiles' => [UserRole::SYSTEM_USER, UserRole::VET],
            };
            $linkedUsers = User::query()
                ->whereIn('role', $roles)
                ->whereIn('roleId', $items->pluck('id'))
                ->orderBy('id')
                ->get(['id', 'username', 'email', 'role', 'roleId'])
                ->keyBy('roleId');
        }

        return $items->map(function (Model $item) use ($resource, $farmMap, $inviteFarmMap, $streetMap, $linkedUsers, $deletedMap): array {
            $data = $item->toArray();
            unset($data['password']);
            $linkedUser = $linkedUsers->get($item->id);
            $deletedAt = $deletedMap->get((string) $item->id);
            $base = [
                'id' => $item->id,
                ...$data,
                'deletedAt' => $deletedAt,
                'createdAt' => $item->created_at?->toIso8601String(),
                'updatedAt' => $item->updated_at?->toIso8601String(),
            ];

            return [...$base, ...match ($resource) {
                'farms' => [
                    'farmName' => $item->name,
                    'farmerName' => $item->farmer ? trim(implode(' ', array_filter([$item->farmer->firstName, $item->farmer->middleName, $item->farmer->surname]))) : 'N/A',
                    'sizeDisplay' => trim(($item->size ?? '—').' '.str_replace('_', ' ', $item->sizeUnit ?? '')),
                    'countryName' => $item->country?->name,
                    'regionName' => $item->region?->name,
                    'districtName' => $item->district?->name,
                    'wardName' => $item->ward?->name,
                    'villageName' => $item->village?->name,
                    'legalStatusName' => $item->legalStatus?->name,
                ],
                'farmers' => (function () use ($item, $streetMap, $linkedUser): array {
                    $rawStreet = $item->getAttribute('streetId') ?? $item->getAttribute('street');
                    $streetId = is_numeric($rawStreet) ? (int) $rawStreet : null;
                    $streetName = $streetId ? ($streetMap->get($streetId) ?? null) : (is_string($rawStreet) && $rawStreet !== '' ? $rawStreet : null);

                    return [
                        'fullName' => trim(implode(' ', array_filter([$item->firstName, $item->middleName, $item->surname]))),
                        'countryName' => $item->country?->name,
                        'regionName' => $item->region?->name,
                        'districtName' => $item->district?->name,
                        'wardName' => $item->ward?->name,
                        'villageName' => $item->village?->name,
                        'streetId' => $streetId,
                        'streetName' => $streetName,
                        'identityCardTypeName' => $item->identityCardType?->name,
                        'schoolLevelName' => $item->schoolLevel?->name,
                        'createdByName' => $item->creator?->username ?? $item->creator?->email ?? 'System',
                        'username' => $linkedUser?->username,
                        'accountEmail' => $linkedUser?->email,
                    ];
                })(),
                'extension-officers' => [
                    'fullName' => trim(implode(' ', array_filter([$item->firstName, $item->middleName, $item->lastName]))),
                    'countryName' => $item->country?->name,
                    'regionName' => $item->region?->name,
                    'districtName' => $item->district?->name,
                    'wardName' => $item->ward?->name,
                    'invitesCount' => (int) $item->farm_invites_count,
                    'username' => $linkedUser?->username,
                ],
                'farm-users' => [
                    'fullName' => trim(implode(' ', array_filter([$item->firstName, $item->middleName, $item->lastName]))),
                    'farmName' => collect($item->getFarmUuidsArray())->map(fn ($uuid) => $farmMap[$uuid] ?? null)->filter()->join(', ') ?: 'N/A',
                    'farmUuid' => $item->getFarmUuidsArray()[0] ?? null,
                    'username' => $linkedUser?->username,
                ],
                'farm-invites' => [
                    'officerName' => $item->extensionOfficer ? trim(implode(' ', array_filter([$item->extensionOfficer->firstName, $item->extensionOfficer->middleName, $item->extensionOfficer->lastName]))) : 'N/A',
                    'farmerName' => $item->farmer ? trim(implode(' ', array_filter([$item->farmer->firstName, $item->farmer->middleName, $item->farmer->surname]))) : 'N/A',
                    'ownerEmail' => $item->farmer?->email,
                    'farmId' => $inviteFarmMap->get($item->farmerId)?->id,
                    'farmName' => $inviteFarmMap->get($item->farmerId)?->name ?? 'N/A',
                ],
                'system-user-profiles' => [
                    'fullName' => trim(implode(' ', array_filter([$item->firstName, $item->middleName, $item->lastName]))),
                    'createdByName' => $item->creator?->username ?? $item->creator?->email ?? 'System',
                    'username' => $linkedUser?->username,
                    'email' => $linkedUser?->email,
                    'accountRole' => $linkedUser?->role ?? UserRole::SYSTEM_USER,
                ],
            }];
        })->values();
    }

    private function stats(string $resource, string $model, ?Collection $deletedIds = null): array
    {
        $deletedIds ??= collect();

        return match ($resource) {
            'farms' => (function () use ($model, $deletedIds): array {
                $row = $model::query()
                    ->when($deletedIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('id', $deletedIds->all()))
                    ->selectRaw('count(*) as total')
                    ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
                    ->selectRaw('count(distinct regionId) as regions')
                    ->first();

                return [
                    ['label' => 'Total farms', 'value' => (int) ($row->total ?? 0), 'note' => 'Registered farm records'],
                    ['label' => 'Active farms', 'value' => (int) ($row->active ?? 0), 'note' => 'Currently operational'],
                    ['label' => 'Regions covered', 'value' => (int) ($row->regions ?? 0), 'note' => 'Geographical coverage'],
                ];
            })(),
            'farmers' => (function () use ($model, $deletedIds): array {
                $row = $model::query()
                    ->when($deletedIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('id', $deletedIds->all()))
                    ->selectRaw('count(*) as total')
                    ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
                    ->selectRaw("sum(case when farmerType = 'organization' then 1 else 0 end) as organizations")
                    ->first();

                return [
                    ['label' => 'Total farmers', 'value' => (int) ($row->total ?? 0), 'note' => 'Registered farmer profiles'],
                    ['label' => 'Active farmers', 'value' => (int) ($row->active ?? 0), 'note' => 'Active profiles'],
                    ['label' => 'Organizations', 'value' => (int) ($row->organizations ?? 0), 'note' => 'Organization profiles'],
                ];
            })(),
            'extension-officers' => (function () use ($model): array {
                $row = $model::query()
                    ->selectRaw('count(*) as total')
                    ->selectRaw('sum(case when isVerified = 1 then 1 else 0 end) as verified')
                    ->first();

                return [
                    ['label' => 'Extension officers', 'value' => (int) ($row->total ?? 0), 'note' => 'Field support profiles'],
                    ['label' => 'Verified officers', 'value' => (int) ($row->verified ?? 0), 'note' => 'Identity verified'],
                    ['label' => 'Farm invitations', 'value' => ExtensionOfficerFarmInvite::query()->count(), 'note' => 'Access relationships'],
                ];
            })(),
            'farm-users' => (function () use ($model): array {
                $row = $model::query()
                    ->selectRaw('count(*) as total')
                    ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
                    ->selectRaw("sum(case when roleTitle = 'farm-manager' then 1 else 0 end) as managers")
                    ->first();

                return [
                    ['label' => 'Farm users', 'value' => (int) ($row->total ?? 0), 'note' => 'Assigned staff profiles'],
                    ['label' => 'Active users', 'value' => (int) ($row->active ?? 0), 'note' => 'Active farm access'],
                    ['label' => 'Farm managers', 'value' => (int) ($row->managers ?? 0), 'note' => 'Management role'],
                ];
            })(),
            'farm-invites' => (function () use ($model): array {
                $row = $model::query()
                    ->selectRaw('count(*) as total')
                    ->selectRaw('count(distinct extensionOfficerId) as officers')
                    ->selectRaw('count(distinct farmerId) as farmers')
                    ->first();

                return [
                    ['label' => 'Farm invitations', 'value' => (int) ($row->total ?? 0), 'note' => 'Officer access codes'],
                    ['label' => 'Invited officers', 'value' => (int) ($row->officers ?? 0), 'note' => 'Unique officers'],
                    ['label' => 'Farm owners', 'value' => (int) ($row->farmers ?? 0), 'note' => 'Owners granting access'],
                ];
            })(),
            'system-user-profiles' => (function () use ($model): array {
                $row = $model::query()
                    ->selectRaw('count(*) as total')
                    ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
                    ->first();

                return [
                    ['label' => 'System profiles', 'value' => (int) ($row->total ?? 0), 'note' => 'Administrative profiles'],
                    ['label' => 'Active profiles', 'value' => (int) ($row->active ?? 0), 'note' => 'Available for linking'],
                    ['label' => 'Linked accounts', 'value' => User::query()->whereIn('role', [UserRole::SYSTEM_USER, UserRole::VET])->count(), 'note' => 'Authentication accounts'],
                ];
            })(),
        };
    }

    private function locationOptions(bool $withStreets = false): array
    {
        return [
            'countries' => $this->simpleOptions('countries'),
            'regions' => $this->simpleOptions('regions', 'countryId'),
            'districts' => $this->simpleOptions('districts', 'regionId'),
            'wards' => $this->simpleOptions('wards', 'districtId'),
            'villages' => $this->simpleOptions('villages', 'wardId'),
            ...($withStreets ? ['streets' => $this->simpleOptions('streets', 'wardId')] : []),
        ];
    }

    private function simpleOptions(string $table, ?string $parent = null): Collection
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }
        $columns = ['id', 'name'];
        if ($parent) {
            $columns[] = $parent;
        }

        return DB::table($table)->orderBy('name')->get($columns)->map(fn ($row) => ['value' => (string) $row->id, 'label' => $row->name, ...($parent ? ['parent' => (string) $row->{$parent}] : [])])->values();
    }

    private function peopleOptions(string $table, string $lastName, string $phone): Collection
    {
        return DB::table($table)->orderBy('firstName')->get(['id', 'firstName', 'middleName', $lastName, $phone])->map(fn ($row) => ['value' => (string) $row->id, 'label' => trim(implode(' ', array_filter([$row->firstName, $row->middleName, $row->{$lastName}]))), 'description' => $row->{$phone}])->values();
    }

    private function farmOptions(bool $useIdValue = false): Collection
    {
        $query = Farm::query()->with('farmer:id,firstName,middleName,surname')->orderBy('name');

        return $query->get(['id', 'uuid', 'farmerId', 'name', 'referenceNo'])->map(function (Farm $farm) use ($useIdValue) {
            $farmerName = $farm->farmer
                ? trim(implode(' ', array_filter([$farm->farmer->firstName, $farm->farmer->middleName, $farm->farmer->surname])))
                : '';

            return [
                'value' => $useIdValue ? (string) $farm->id : $farm->uuid,
                'label' => $farm->name,
                'description' => implode(' · ', array_filter([
                    $farm->referenceNo,
                    $farmerName !== '' ? "Farmer: {$farmerName}" : null,
                ])) ?: null,
            ];
        })->values();
    }

    private function userOptions(): Collection
    {
        return User::query()->orderBy('username')->get(['id', 'username', 'email'])->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->username ?: $user->email, 'description' => $user->email])->values();
    }

    private function inviteData(array $data, ?Model $existing = null): array
    {
        $farmId = $data['farmId'] ?? null;
        unset($data['farmId']);
        if ($farmId) {
            $farm = Farm::query()->findOrFail($farmId);
            $data['farmerId'] = $farm->farmerId;
        }
        if (! $existing && empty($data['access_code'])) {
            $data['access_code'] = ExtensionOfficerFarmInvite::generateUniqueAccessCode();
        }

        return $data;
    }

    private function normalizeFarmerPayload(array $data): array
    {
        if (array_key_exists('streetId', $data) && ! Schema::hasColumn('farmers', 'streetId')) {
            if (Schema::hasColumn('farmers', 'street')) {
                $data['street'] = $data['streetId'] !== null ? (string) $data['streetId'] : null;
            }
            unset($data['streetId']);
        }

        return $data;
    }

    private function normalizeFarmUuid(string $value): string
    {
        return $value;
    }

    private function generateFarmerNumber(): string
    {
        $date = now()->format('Ymd');
        do {
            $partFour = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $partThree = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
            $candidate = "FMR-{$date}-{$partFour}-{$partThree}";
        } while (Farmer::where('farmerNo', $candidate)->exists());

        return $candidate;
    }

    private function ensureLinkedUserAccount(
        string $resource,
        Model $record,
        ?string $username,
        ?string $accountEmail,
        ?string $password,
        int $actorId,
        ?string $accountRole = null,
    ): User {
        $role = match ($resource) {
            'farmers' => UserRole::FARMER,
            'extension-officers' => UserRole::EXTENSION_OFFICER,
            'farm-users' => UserRole::FARM_INVITED_USER,
            'system-user-profiles' => in_array($accountRole, [UserRole::SYSTEM_USER, UserRole::VET], true)
                ? $accountRole
                : UserRole::SYSTEM_USER,
        };

        $existing = User::query()
            ->where('role', $role)
            ->where('roleId', $record->id)
            ->first();

        $baseUsername = $username
            ?: ($resource === 'farmers'
                ? ($record->surname ?: $record->firstName)
                : ($record->lastName ?: $record->firstName));

        $resolvedUsername = $this->uniqueUsername($baseUsername ?: 'user', $existing?->id);
        $rawEmail = $accountEmail ?: ($record->email ?? null);
        $resolvedEmail = $this->uniqueEmail($rawEmail ?: "{$resolvedUsername}@tagandseal.local", $existing?->id);
        $resolvedStatus = ($record->status ?? 'active') === 'notActive' ? UserStatus::NOT_ACTIVE : UserStatus::ACTIVE;
        $defaultPassword = $password ?: ($rawEmail ?: ($record->phone1 ?? $record->phone ?? 'password123'));

        if ($existing) {
            $existing->update([
                'username' => $resolvedUsername,
                'email' => $resolvedEmail,
                'status' => $resolvedStatus,
                'updatedBy' => $actorId,
                ...(filled($password) ? ['password' => $password] : []),
            ]);

            return $existing;
        }

        return User::create([
            'username' => $resolvedUsername,
            'email' => $resolvedEmail,
            'password' => $defaultPassword,
            'role' => $role,
            'roleId' => $record->id,
            'status' => $resolvedStatus,
            'createdBy' => $actorId,
            'updatedBy' => $actorId,
        ]);
    }

    private function syncLinkedAccount(
        string $resource,
        Model $record,
        ?string $username,
        ?string $accountEmail,
        ?string $password,
        int $updaterId,
        ?string $accountRole = null,
    ): void {
        if (! in_array($resource, ['farmers', 'extension-officers', 'farm-users', 'system-user-profiles'], true)) {
            return;
        }

        $roles = match ($resource) {
            'farmers' => [UserRole::FARMER],
            'extension-officers' => [UserRole::EXTENSION_OFFICER],
            'farm-users' => [UserRole::FARM_INVITED_USER],
            'system-user-profiles' => [UserRole::SYSTEM_USER, UserRole::VET],
        };

        $linked = User::query()
            ->whereIn('role', $roles)
            ->where('roleId', $record->id)
            ->first();

        if (! $linked) {
            $this->ensureLinkedUserAccount($resource, $record, $username, $accountEmail, $password, $updaterId, $accountRole);

            return;
        }

        $nextUsername = filled($username)
            ? $this->uniqueUsername($username, $linked->id)
            : ($resource === 'farm-users' && filled($record->lastName)
                ? $this->uniqueUsername($record->lastName, $linked->id)
                : $linked->username);

        $rawEmail = $accountEmail ?: ($record->email ?? null);
        $nextEmail = filled($rawEmail)
            ? $this->uniqueEmail($rawEmail, $linked->id)
            : $linked->email;

        $nextStatus = isset($record->status)
            ? ($record->status === 'notActive' ? UserStatus::NOT_ACTIVE : UserStatus::ACTIVE)
            : ($resource === 'extension-officers'
                ? ($record->isVerified ? UserStatus::ACTIVE : UserStatus::NOT_ACTIVE)
                : $linked->status);

        $nextRole = ($resource === 'system-user-profiles' && in_array($accountRole, [UserRole::SYSTEM_USER, UserRole::VET], true))
            ? $accountRole
            : $linked->role;

        $payload = [
            'username' => $nextUsername,
            'email' => $nextEmail,
            'role' => $nextRole,
            'status' => $nextStatus,
            'updatedBy' => $updaterId,
        ];

        if (filled($password)) {
            $payload['password'] = $password;
        }

        $linked->update($payload);
    }

    private function uniqueUsername(string $base, ?int $ignoreId = null): string
    {
        $clean = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '', $base)) ?: 'user';
        $candidate = $clean;
        $counter = 1;
        while (User::query()->where('username', $candidate)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $candidate = $clean.$counter++;
        }

        return $candidate;
    }

    private function uniqueEmail(string $email, ?int $ignoreId = null): string
    {
        $candidate = strtolower(trim($email));
        if (! str_contains($candidate, '@')) {
            $candidate .= '@tagandseal.local';
        }
        [$local, $domain] = explode('@', $candidate, 2);
        $counter = 1;
        while (User::query()->where('email', $candidate)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists()) {
            $candidate = "{$local}{$counter}@{$domain}";
            $counter++;
        }

        return $candidate;
    }

    private function freshPresented(Model $record, string $resource): array
    {
        $query = $record->newQuery();
        $this->eagerLoad($query, $resource);

        return $this->present(collect([$query->findOrFail($record->id)]), $resource, $this->softDeletedMap($resource))->first();
    }

    private function softDeleteEntityType(string $resource): string
    {
        return "admin_soft_delete:{$resource}";
    }

    private function softDeletedMap(string $resource): Collection
    {
        if (! in_array($resource, ['farms', 'farmers'], true) || ! Schema::hasTable('platform_change_journal')) {
            return collect();
        }

        return DB::table('platform_change_journal')
            ->where('entity_type', $this->softDeleteEntityType($resource))
            ->whereNull('replayed_at')
            ->get(['legacy_key', 'captured_at'])
            ->mapWithKeys(fn ($row) => [(string) $row->legacy_key => (string) $row->captured_at]);
    }

    private function label(string $resource): string
    {
        return match ($resource) {
            'farms' => 'Farm', 'farmers' => 'Farmer', 'extension-officers' => 'Extension officer', 'farm-users' => 'Farm user', 'farm-invites' => 'Farm invitation', 'system-user-profiles' => 'System user profile'
        };
    }
}
