<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ExtensionOfficer;
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

class UserAccountController extends Controller
{
    private const FARM_ROLES = [
        'farm-manager',
        'feeding-user',
        'weight-change-user',
        'deworming-user',
        'medication-user',
        'vaccination-user',
        'disposal-user',
        'birth-event-user',
        'aborted-pregnancy-user',
        'dryoff-user',
        'insemination-user',
        'pregnancy-user',
        'milking-user',
        'transfer-user',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(UserRole::all())],
            'status' => ['nullable', Rule::in(UserStatus::all())],
            'sort' => ['nullable', Rule::in(['created_at', 'username', 'email', 'role', 'status', 'desc', 'asc', 'alpha-asc', 'alpha-desc'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query()->select([
            'id', 'uuid', 'username', 'email', 'role', 'roleId', 'status',
            'created_at', 'updated_at',
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where('username', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere(fn (Builder $profileQuery) => $this->addProfileSearch($profileQuery, $like));
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $rawSort = $validated['sort'] ?? 'created_at';
        [$sort, $direction] = match ($rawSort) {
            'desc' => ['created_at', 'desc'],
            'asc' => ['created_at', 'asc'],
            'alpha-asc' => ['username', 'asc'],
            'alpha-desc' => ['username', 'desc'],
            default => [$rawSort, $validated['direction'] ?? 'desc'],
        };
        $paginator = $query->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        $stats = User::query()->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
            ->selectRaw("sum(case when role = 'systemUser' then 1 else 0 end) as system_users")
            ->selectRaw("sum(case when role = 'farmer' then 1 else 0 end) as farmers")
            ->first();

        return response()->json([
            'status' => true,
            'data' => $this->presentUsers(collect($paginator->items()))->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'stats' => [
                    'total' => (int) ($stats->total ?? 0),
                    'active' => (int) ($stats->active ?? 0),
                    'systemUsers' => (int) ($stats->system_users ?? 0),
                    'farmers' => (int) ($stats->farmers ?? 0),
                ],
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $this->presentUsers(collect([$user]))->first(),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $permissions = Schema::hasTable('platform_permissions')
            ? DB::table('platform_permissions')->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($permission) => ['id' => (int) $permission->id, 'code' => $permission->code, 'name' => $permission->name])
                ->values()
            : collect();

        return response()->json([
            'status' => true,
            'data' => [
                'roles' => collect(UserRole::all())->map(fn (string $role) => [
                    'value' => $role,
                    'label' => UserRole::getDisplayName($role),
                    'description' => UserRole::getDescription($role),
                ])->values(),
                'statuses' => collect(UserStatus::all())->map(fn (string $status) => [
                    'value' => $status,
                    'label' => UserStatus::getDisplayName($status),
                ])->values(),
                'permissions' => $permissions,
                'farms' => $this->farmOptions(),
                'countries' => $this->simpleOptions('countries'),
                'regions' => $this->simpleOptions('regions', 'countryId'),
                'districts' => $this->simpleOptions('districts', 'regionId'),
                'wards' => $this->simpleOptions('wards', 'districtId'),
                'villages' => $this->simpleOptions('villages', 'wardId'),
                'streets' => $this->simpleOptions('streets', 'wardId'),
                'identityCardTypes' => $this->simpleOptions('identity_card_types'),
                'schoolLevels' => $this->simpleOptions('school_levels'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateUserAndProfile($request);
        if (($validated['role'] ?? null) === UserRole::SYSTEM_USER || ! empty($validated['platformPermissions'])) {
            abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
        }
        if (($validated['role'] ?? null) === UserRole::SYSTEM_USER) {
            abort_unless($request->user()->platform_role === 'superAdmin', 403);
        }
        $this->guardPermissionManagementGrant($request, $validated['platformPermissions'] ?? []);

        $user = DB::transaction(function () use ($request, $validated): User {
            $permissionIds = $validated['platformPermissions'] ?? [];
            $creatorId = $request->user()->id;

            $profile = $this->createRoleProfile($validated['role'], $validated, $creatorId);

            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
                'roleId' => $profile->id,
                'status' => $validated['status'] ?? UserStatus::ACTIVE,
                'createdBy' => $creatorId,
                'updatedBy' => $creatorId,
            ]);
            if ($validated['role'] === UserRole::SYSTEM_USER) {
                $user->forceFill(['platform_role' => 'admin'])->save();
            }

            $this->syncPermissions($user, $permissionIds, $request->user()->uuid);

            return $user;
        });

        return response()->json([
            'status' => true,
            'message' => 'User account and role profile created successfully.',
            'data' => $this->presentUsers(collect([$user->fresh()]))->first(),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $this->validateUserAndProfile($request, $user);
        $nextRole = $validated['role'] ?? $user->role;
        $nextStatus = $validated['status'] ?? $user->status;
        if ($user->platform_role !== 'appUser' && ! $request->user()->is($user)) {
            abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
        }
        if ($user->platform_role === 'superAdmin' && ! $request->user()->is($user)) {
            abort_unless($request->user()->platform_role === 'superAdmin', 403);
        }
        if (array_key_exists('platformPermissions', $validated)) {
            abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
            abort_if($request->user()->is($user), 403, 'You cannot change your own permissions.');
            $this->guardPermissionManagementGrant($request, $validated['platformPermissions']);
        }
        if ($nextRole === UserRole::SYSTEM_USER && $user->role !== UserRole::SYSTEM_USER) {
            abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
            abort_unless($request->user()->platform_role === 'superAdmin', 403);
        }
        $this->guardProtectedAdmin($request, $user, $nextRole, $nextStatus);

        DB::transaction(function () use ($request, $user, $validated, $nextRole, $nextStatus): void {
            $permissionIds = $validated['platformPermissions'] ?? null;
            $updaterId = $request->user()->id;

            $roleId = (int) ($user->roleId ?? 0);
            if ($nextRole !== $user->role || $roleId <= 0) {
                $profile = $this->createRoleProfile($nextRole, [
                    ...$validated,
                    'email' => $validated['email'] ?? $user->email,
                    'password' => $validated['password'] ?? 'password123',
                    'status' => $nextStatus,
                ], $updaterId);
                $roleId = $profile->id;
            } else {
                $this->updateRoleProfile($nextRole, $roleId, $validated, $nextStatus);
            }

            $userPayload = [
                'username' => $validated['username'] ?? $user->username,
                'email' => $validated['email'] ?? $user->email,
                'role' => $nextRole,
                'roleId' => $roleId,
                'status' => $nextStatus,
                'updatedBy' => $updaterId,
            ];
            if (! empty($validated['password'])) {
                $userPayload['password'] = $validated['password'];
            }

            $user->update($userPayload);
            if ($nextStatus !== UserStatus::ACTIVE || ! empty($validated['password'])) {
                $user->tokens()->delete();
            }

            if (is_array($permissionIds)) {
                $this->syncPermissions($user, $permissionIds, $request->user()->uuid);
            }
        });

        return response()->json([
            'status' => true,
            'message' => 'User account updated successfully.',
            'data' => $this->presentUsers(collect([$user->fresh()]))->first(),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            return response()->json(['status' => false, 'message' => 'You cannot delete your own account.'], 422);
        }
        if ($user->platform_role !== 'appUser') {
            abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
        }
        if ($user->platform_role === 'superAdmin') {
            abort_unless($request->user()->platform_role === 'superAdmin', 403);
            abort_if(User::query()->where('platform_role', 'superAdmin')->where('status', UserStatus::ACTIVE)->count() <= 1, 403, 'The last active super administrator cannot be removed.');
        }
        if ($user->role === UserRole::SYSTEM_USER && User::query()->where('role', UserRole::SYSTEM_USER)->count() <= 1) {
            return response()->json(['status' => false, 'message' => 'The final system administrator cannot be deleted.'], 422);
        }

        DB::transaction(function () use ($user): void {
            if (Schema::hasTable('user_platform_permissions')) {
                DB::table('user_platform_permissions')->where('user_uuid', $user->uuid)->delete();
            }
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(['status' => true, 'message' => 'User account deleted successfully.']);
    }

    private function validateUserAndProfile(Request $request, ?User $user = null): array
    {
        $role = (string) $request->input('role', $user?->role ?? UserRole::SYSTEM_USER);
        $isCreate = $user === null;
        $reqOnCreate = $isCreate ? 'required' : 'sometimes';

        $baseRules = [
            'username' => [$reqOnCreate, 'string', 'max:255', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => [$reqOnCreate, 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$isCreate ? 'required' : 'nullable', 'string', 'min:8', 'max:255'],
            'role' => [$reqOnCreate, Rule::in(UserRole::all())],
            'status' => [$reqOnCreate, Rule::in(UserStatus::all())],
            'platformPermissions' => ['sometimes', 'array'],
            'platformPermissions.*' => ['integer', Rule::exists('platform_permissions', 'id')],
        ];

        $roleRules = match ($role) {
            UserRole::FARMER => [
                'firstName' => [$reqOnCreate, 'string', 'max:255'],
                'middleName' => ['nullable', 'string', 'max:255'],
                'surname' => [$reqOnCreate, 'string', 'max:255'],
                'phone1' => [$reqOnCreate, 'string', 'max:40'],
                'phone2' => ['nullable', 'string', 'max:40'],
                'gender' => [$reqOnCreate, Rule::in(['male', 'female'])],
                'farmerType' => ['nullable', Rule::in(['individual', 'organization'])],
                'dateOfBirth' => ['nullable', 'date'],
                'physicalAddress' => ['nullable', 'string', 'max:500'],
                'farmerOrganizationMembership' => ['nullable', 'string', 'max:255'],
                'identityCardTypeId' => ['nullable', 'integer', Rule::exists('identity_card_types', 'id')],
                'identityNumber' => ['nullable', 'string', 'max:255'],
                'schoolLevelId' => ['nullable', 'integer', Rule::exists('school_levels', 'id')],
                'countryId' => ['nullable', 'integer', Rule::exists('countries', 'id')],
                'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
                'districtId' => ['nullable', 'integer', Rule::exists('districts', 'id')],
                'wardId' => ['nullable', 'integer', Rule::exists('wards', 'id')],
                'villageId' => ['nullable', 'integer', Rule::exists('villages', 'id')],
                'streetId' => ['nullable', 'integer', Rule::exists('streets', 'id')],
            ],
            UserRole::EXTENSION_OFFICER => [
                'firstName' => [$reqOnCreate, 'string', 'max:255'],
                'middleName' => ['nullable', 'string', 'max:255'],
                'lastName' => [$reqOnCreate, 'string', 'max:255'],
                'phone' => [$reqOnCreate, 'string', 'max:40'],
                'gender' => [$reqOnCreate, Rule::in(['male', 'female'])],
                'licenseNumber' => ['nullable', 'string', 'max:255'],
                'organization' => ['nullable', 'string', 'max:255'],
                'specialization' => ['nullable', 'string', 'max:100'],
                'address' => ['nullable', 'string', 'max:500'],
                'isVerified' => ['sometimes', 'boolean'],
                'countryId' => [$reqOnCreate, 'integer', Rule::exists('countries', 'id')],
                'regionId' => [$reqOnCreate, 'integer', Rule::exists('regions', 'id')],
                'districtId' => [$reqOnCreate, 'integer', Rule::exists('districts', 'id')],
                'wardId' => [$reqOnCreate, 'integer', Rule::exists('wards', 'id')],
            ],
            UserRole::FARM_INVITED_USER => [
                'farmUuid' => [$reqOnCreate, 'string', Rule::exists('farms', 'uuid')],
                'roleTitle' => [$reqOnCreate, Rule::in(self::FARM_ROLES)],
                'firstName' => [$reqOnCreate, 'string', 'max:255'],
                'middleName' => ['nullable', 'string', 'max:255'],
                'lastName' => [$reqOnCreate, 'string', 'max:255'],
                'phone' => [$reqOnCreate, 'string', 'max:40'],
                'gender' => [$reqOnCreate, Rule::in(['male', 'female'])],
            ],
            UserRole::SYSTEM_USER, UserRole::VET => [
                'firstName' => [$reqOnCreate, 'string', 'max:255'],
                'middleName' => ['nullable', 'string', 'max:255'],
                'lastName' => [$reqOnCreate, 'string', 'max:255'],
                'phone' => [$reqOnCreate, 'string', 'max:40'],
                'address' => ['nullable', 'string', 'max:500'],
            ],
            default => [],
        };

        return $request->validate([...$baseRules, ...$roleRules]);
    }

    private function createRoleProfile(string $role, array $data, int $creatorId): Model
    {
        return match ($role) {
            UserRole::FARMER => Farmer::create($this->normalizeFarmerPayload([
                'farmerNo' => $this->generateFarmerNumber(),
                'firstName' => $data['firstName'],
                'middleName' => $data['middleName'] ?? null,
                'surname' => $data['surname'],
                'phone1' => $data['phone1'],
                'phone2' => $data['phone2'] ?? null,
                'email' => $data['email'],
                'physicalAddress' => $data['physicalAddress'] ?? null,
                'farmerOrganizationMembership' => $data['farmerOrganizationMembership'] ?? null,
                'dateOfBirth' => $data['dateOfBirth'] ?? null,
                'gender' => $data['gender'],
                'identityCardTypeId' => $data['identityCardTypeId'] ?? null,
                'identityNumber' => $data['identityNumber'] ?? null,
                'streetId' => $data['streetId'] ?? null,
                'schoolLevelId' => $data['schoolLevelId'] ?? null,
                'villageId' => $data['villageId'] ?? null,
                'wardId' => $data['wardId'] ?? null,
                'districtId' => $data['districtId'] ?? null,
                'regionId' => $data['regionId'] ?? null,
                'countryId' => $data['countryId'] ?? $this->defaultCountryId(),
                'farmerType' => $data['farmerType'] ?? 'individual',
                'createdBy' => $creatorId,
                'status' => ($data['status'] ?? UserStatus::ACTIVE) === UserStatus::NOT_ACTIVE ? 'notActive' : 'active',
            ])),
            UserRole::EXTENSION_OFFICER => ExtensionOfficer::create([
                'firstName' => $data['firstName'],
                'middleName' => $data['middleName'] ?? null,
                'lastName' => $data['lastName'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => Hash::make($data['password'] ?? 'password'),
                'gender' => $data['gender'],
                'licenseNumber' => $data['licenseNumber'] ?? null,
                'organization' => $data['organization'] ?? null,
                'specialization' => $data['specialization'] ?? null,
                'address' => $data['address'] ?? null,
                'isVerified' => (bool) ($data['isVerified'] ?? false),
                'countryId' => $data['countryId'],
                'regionId' => $data['regionId'],
                'districtId' => $data['districtId'],
                'wardId' => $data['wardId'],
            ]),
            UserRole::FARM_INVITED_USER => FarmUser::create([
                'uuid' => (string) Str::uuid(),
                'farmUuid' => $data['farmUuid'],
                'roleTitle' => $data['roleTitle'],
                'firstName' => $data['firstName'],
                'middleName' => $data['middleName'] ?? null,
                'lastName' => $data['lastName'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'gender' => $data['gender'],
                'status' => ($data['status'] ?? UserStatus::ACTIVE) === UserStatus::NOT_ACTIVE ? 'notActive' : 'active',
            ]),
            UserRole::SYSTEM_USER, UserRole::VET => SystemUser::create([
                'firstName' => $data['firstName'],
                'middleName' => $data['middleName'] ?? null,
                'lastName' => $data['lastName'],
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'status' => ($data['status'] ?? UserStatus::ACTIVE) === UserStatus::NOT_ACTIVE ? 'notActive' : 'active',
                'createdBy' => $creatorId,
            ]),
            default => throw ValidationException::withMessages(['role' => 'Unsupported user role.']),
        };
    }

    private function updateRoleProfile(string $role, int $roleId, array $data, string $nextStatus): void
    {
        $profileStatus = $nextStatus === UserStatus::NOT_ACTIVE ? 'notActive' : 'active';

        if ($role === UserRole::FARMER) {
            $farmer = Farmer::find($roleId);
            if (! $farmer) {
                return;
            }
            $fields = [
                'firstName', 'middleName', 'surname', 'phone1', 'phone2', 'email',
                'physicalAddress', 'farmerOrganizationMembership', 'dateOfBirth', 'gender',
                'identityCardTypeId', 'identityNumber', 'streetId', 'schoolLevelId',
                'villageId', 'wardId', 'districtId', 'regionId', 'countryId', 'farmerType',
            ];
            $payload = ['status' => $profileStatus];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }
            $farmer->update($this->normalizeFarmerPayload($payload));

            return;
        }

        if ($role === UserRole::EXTENSION_OFFICER) {
            $officer = ExtensionOfficer::find($roleId);
            if (! $officer) {
                return;
            }
            $fields = [
                'firstName', 'middleName', 'lastName', 'email', 'phone', 'gender',
                'licenseNumber', 'organization', 'specialization', 'address',
                'isVerified', 'countryId', 'regionId', 'districtId', 'wardId',
            ];
            $payload = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }
            if (! empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
            }
            if ($payload !== []) {
                $officer->update($payload);
            }

            return;
        }

        if ($role === UserRole::FARM_INVITED_USER) {
            $farmUser = FarmUser::find($roleId);
            if (! $farmUser) {
                return;
            }
            $fields = ['farmUuid', 'roleTitle', 'firstName', 'middleName', 'lastName', 'phone', 'email', 'gender'];
            $payload = ['status' => $profileStatus];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }
            $farmUser->update($payload);

            return;
        }

        if (in_array($role, [UserRole::SYSTEM_USER, UserRole::VET], true)) {
            $sysUser = SystemUser::find($roleId);
            if (! $sysUser) {
                return;
            }
            $fields = ['firstName', 'middleName', 'lastName', 'phone', 'address'];
            $payload = ['status' => $profileStatus];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }
            $sysUser->update($payload);
        }
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

    private function defaultCountryId(): int
    {
        return (int) (DB::table('countries')->orderBy('id')->value('id') ?? 1);
    }

    private function generateFarmerNumber(): string
    {
        return \App\Services\FarmerNumberGenerator::generate();
    }

    private function guardProtectedAdmin(Request $request, User $user, string $nextRole, string $nextStatus): void
    {
        if (! $request->user()->is($user)) {
            return;
        }
        if ($nextRole !== UserRole::SYSTEM_USER || $nextStatus !== UserStatus::ACTIVE) {
            throw ValidationException::withMessages(['status' => 'You cannot remove administrator access from your current session.']);
        }
    }

    private function addProfileSearch(Builder $query, string $like): void
    {
        $query->where(function (Builder $profiles) use ($like): void {
            $profiles->where(function (Builder $farmer) use ($like): void {
                $farmer->where('role', UserRole::FARMER)->whereExists(fn ($sub) => $sub->selectRaw('1')->from('farmers')->whereColumn('farmers.id', 'users.roleId')->whereRaw("concat_ws(' ', firstName, middleName, surname) like ?", [$like]));
            })->orWhere(function (Builder $system) use ($like): void {
                $system->whereIn('role', [UserRole::SYSTEM_USER, UserRole::VET])->whereExists(fn ($sub) => $sub->selectRaw('1')->from('system_users')->whereColumn('system_users.id', 'users.roleId')->whereRaw("concat_ws(' ', firstName, middleName, lastName) like ?", [$like]));
            })->orWhere(function (Builder $officer) use ($like): void {
                $officer->where('role', UserRole::EXTENSION_OFFICER)->whereExists(fn ($sub) => $sub->selectRaw('1')->from('extension_officers')->whereColumn('extension_officers.id', 'users.roleId')->whereRaw("concat_ws(' ', firstName, middleName, lastName) like ?", [$like]));
            })->orWhere(function (Builder $farmUser) use ($like): void {
                $farmUser->where('role', UserRole::FARM_INVITED_USER)->whereExists(fn ($sub) => $sub->selectRaw('1')->from('farm_users')->whereColumn('farm_users.id', 'users.roleId')->whereRaw("concat_ws(' ', firstName, middleName, lastName) like ?", [$like]));
            });
        });
    }

    private function presentUsers(Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        $profiles = [];
        $profileDetails = [];
        $lookupIds = [
            'countries' => collect(),
            'regions' => collect(),
            'districts' => collect(),
            'wards' => collect(),
            'villages' => collect(),
            'streets' => collect(),
            'identity_card_types' => collect(),
            'school_levels' => collect(),
            'users' => collect(),
            'farms' => collect(),
        ];

        foreach ([
            UserRole::FARMER => ['farmers', 'surname', 'phone1'],
            UserRole::SYSTEM_USER => ['system_users', 'lastName', 'phone'],
            UserRole::VET => ['system_users', 'lastName', 'phone'],
            UserRole::EXTENSION_OFFICER => ['extension_officers', 'lastName', 'phone'],
            UserRole::FARM_INVITED_USER => ['farm_users', 'lastName', 'phone'],
        ] as $role => [$table, $lastName, $phone]) {
            $ids = $users->where('role', $role)->pluck('roleId')->filter()->unique();
            if ($ids->isEmpty() || ! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->whereIn('id', $ids)->get();
            foreach ($rows as $row) {
                $rowArray = (array) $row;
                unset($rowArray['password']);
                if (isset($rowArray['street']) && ! isset($rowArray['streetId']) && is_numeric($rowArray['street'])) {
                    $rowArray['streetId'] = (int) $rowArray['street'];
                }
                foreach ([
                    'countryId' => 'countries',
                    'regionId' => 'regions',
                    'districtId' => 'districts',
                    'wardId' => 'wards',
                    'villageId' => 'villages',
                    'streetId' => 'streets',
                    'identityCardTypeId' => 'identity_card_types',
                    'schoolLevelId' => 'school_levels',
                    'createdBy' => 'users',
                ] as $field => $lookupTable) {
                    if (! empty($rowArray[$field]) && is_numeric($rowArray[$field])) {
                        $lookupIds[$lookupTable]->push((int) $rowArray[$field]);
                    }
                }
                if (! empty($rowArray['farmUuid'])) {
                    $lookupIds['farms']->push((string) $rowArray['farmUuid']);
                }
                $profiles[$role][(int) $row->id] = [
                    'id' => (int) $row->id,
                    'name' => trim(implode(' ', array_filter([$row->firstName ?? null, $row->middleName ?? null, $row->{$lastName} ?? null]))),
                    'phone' => $row->{$phone} ?? null,
                ];
                $profileDetails[$role][(int) $row->id] = $rowArray;
            }
        }

        $lookupMaps = [];
        foreach (['countries', 'regions', 'districts', 'wards', 'villages', 'streets', 'identity_card_types', 'school_levels'] as $lookupTable) {
            $ids = $lookupIds[$lookupTable]->unique()->values();
            $lookupMaps[$lookupTable] = ($ids->isNotEmpty() && Schema::hasTable($lookupTable))
                ? DB::table($lookupTable)->whereIn('id', $ids)->pluck('name', 'id')
                : collect();
        }
        $creatorIds = $lookupIds['users']->unique()->values();
        $creatorMap = ($creatorIds->isNotEmpty() && Schema::hasTable('users'))
            ? DB::table('users')->whereIn('id', $creatorIds)->get(['id', 'username', 'email'])->mapWithKeys(fn ($u) => [(int) $u->id => $u->username ?: $u->email])
            : collect();
        $farmUuids = $lookupIds['farms']->unique()->values();
        $farmMap = ($farmUuids->isNotEmpty() && Schema::hasTable('farms'))
            ? DB::table('farms')->whereIn('uuid', $farmUuids)->pluck('name', 'uuid')
            : collect();

        foreach ($profileDetails as $role => $recordsByRole) {
            foreach ($recordsByRole as $id => $details) {
                $details['countryName'] = ! empty($details['countryId']) ? ($lookupMaps['countries']->get((int) $details['countryId']) ?? null) : null;
                $details['regionName'] = ! empty($details['regionId']) ? ($lookupMaps['regions']->get((int) $details['regionId']) ?? null) : null;
                $details['districtName'] = ! empty($details['districtId']) ? ($lookupMaps['districts']->get((int) $details['districtId']) ?? null) : null;
                $details['wardName'] = ! empty($details['wardId']) ? ($lookupMaps['wards']->get((int) $details['wardId']) ?? null) : null;
                $details['villageName'] = ! empty($details['villageId']) ? ($lookupMaps['villages']->get((int) $details['villageId']) ?? null) : null;
                $details['streetName'] = ! empty($details['streetId']) ? ($lookupMaps['streets']->get((int) $details['streetId']) ?? null) : null;
                $details['identityCardTypeName'] = ! empty($details['identityCardTypeId']) ? ($lookupMaps['identity_card_types']->get((int) $details['identityCardTypeId']) ?? null) : null;
                $details['schoolLevelName'] = ! empty($details['schoolLevelId']) ? ($lookupMaps['school_levels']->get((int) $details['schoolLevelId']) ?? null) : null;
                $details['createdByName'] = ! empty($details['createdBy']) ? ($creatorMap->get((int) $details['createdBy']) ?? null) : null;
                $details['farmName'] = ! empty($details['farmUuid']) ? ($farmMap->get((string) $details['farmUuid']) ?? null) : null;
                $profileDetails[$role][$id] = $details;
            }
        }

        $permissions = collect();
        if (Schema::hasTable('user_platform_permissions') && Schema::hasTable('platform_permissions')) {
            $permissions = DB::table('user_platform_permissions as assignments')
                ->join('platform_permissions as permissions', 'permissions.id', '=', 'assignments.permission_id')
                ->whereIn('assignments.user_uuid', $users->pluck('uuid')->filter())
                ->get(['assignments.user_uuid', 'permissions.id', 'permissions.code', 'permissions.name'])
                ->groupBy('user_uuid');
        }

        return $users->map(function (User $user) use ($profiles, $profileDetails, $permissions): array {
            $profile = $profiles[$user->role][(int) $user->roleId] ?? null;
            $details = $profileDetails[$user->role][(int) $user->roleId] ?? null;

            return [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'roleLabel' => UserRole::getDisplayName($user->role),
                'roleId' => $user->roleId,
                'profile' => $profile,
                'profileDetails' => $details,
                'status' => $user->status,
                'statusLabel' => UserStatus::getDisplayName($user->status),
                'permissions' => collect($permissions->get($user->uuid, []))->map(fn ($permission) => [
                    'id' => (int) $permission->id,
                    'code' => $permission->code,
                    'name' => $permission->name,
                ])->values(),
                'createdAt' => $user->created_at?->toIso8601String(),
                'updatedAt' => $user->updated_at?->toIso8601String(),
            ];
        });
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

        return DB::table($table)->orderBy('name')->get($columns)->map(fn ($row) => [
            'value' => (string) $row->id,
            'label' => $row->name,
            ...($parent ? ['parent' => (string) $row->{$parent}] : []),
        ])->values();
    }

    private function farmOptions(): Collection
    {
        if (! Schema::hasTable('farms')) {
            return collect();
        }

        return Farm::query()
            ->with('farmer:id,firstName,middleName,surname')
            ->orderBy('name')
            ->get(['id', 'uuid', 'farmerId', 'name', 'referenceNo'])
            ->map(function (Farm $farm) {
                $farmerName = $farm->farmer
                    ? trim(implode(' ', array_filter([$farm->farmer->firstName, $farm->farmer->middleName, $farm->farmer->surname])))
                    : '';

                return [
                    'value' => $farm->uuid,
                    'label' => $farm->name,
                    'description' => implode(' · ', array_filter([
                        $farm->referenceNo,
                        $farmerName !== '' ? "Farmer: {$farmerName}" : null,
                    ])) ?: null,
                ];
            })->values();
    }

    private function syncPermissions(User $user, array $permissionIds, ?string $grantedByUuid): void
    {
        if (! Schema::hasTable('user_platform_permissions')) {
            return;
        }
        DB::table('user_platform_permissions')->where('user_uuid', $user->uuid)->delete();
        if (empty($permissionIds)) {
            return;
        }
        $now = now();
        DB::table('user_platform_permissions')->insert(collect($permissionIds)->unique()->map(fn ($permissionId) => [
            'user_uuid' => $user->uuid,
            'permission_id' => $permissionId,
            'granted_by_uuid' => $grantedByUuid,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    private function guardPermissionManagementGrant(Request $request, array $permissionIds): void
    {
        if ($request->user()->platform_role !== 'superAdmin' && DB::table('platform_permissions')->where('code', 'permissions.manage')->whereIn('id', $permissionIds)->exists()) {
            abort(403, 'Only a super administrator can grant permission management access.');
        }
    }
}
