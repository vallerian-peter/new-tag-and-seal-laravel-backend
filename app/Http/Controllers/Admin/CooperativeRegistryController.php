<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;
use App\Services\Dairy\FactoryMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CooperativeRegistryController extends Controller
{
    /** Registrar format, e.g. PRI-KJR-HAI-DC-2023-3677: code, place segments, year, serial. */
    public const REGISTRATION_PATTERN = '/^[A-Z]{2,6}(-[A-Z0-9]{2,8}){1,5}-(19|20)\d{2}-\d{1,8}$/';

    private const LOCATION_PARENTS = ['countries' => null, 'regions' => 'countryId', 'districts' => 'regionId', 'wards' => 'districtId', 'villages' => 'wardId'];

    private function table(string $type): string
    {
        return match ($type) {
            'mcus' => 'cooperative_mcus',
            'amcos' => 'cooperative_amcos',
            'centres' => 'cooperative_collection_centres',
            'factories' => 'dairy_factories',
            default => abort(404),
        };
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $super = $request->user()->platform_role === 'superAdmin';
        $allowedAmcos = null;
        if ($type === 'amcos' && ! $super) {
            $memberships = DB::table('cooperative_user_memberships')->where('user_id', $request->user()->id)
                ->where('status', 'approved')->whereIn('role', CooperativeAccess::ADMIN_ROLES)->get();
            abort_if($memberships->isEmpty(), 403);
            $mcuUuids = $memberships->where('role', 'mcu_admin')->pluck('mcu_uuid')->filter()->all();
            $allowedAmcos = $memberships->pluck('amcos_uuid')
                ->merge(DB::table('cooperative_amcos')->whereIn('mcu_uuid', $mcuUuids)->pluck('uuid'))
                ->unique()->values()->all();
        } else {
            abort_unless($type === 'centres' || $type === 'factories' || $super, 403);
        }
        $table = $this->table($type);
        $data = $request->validate([
            'search' => 'nullable|string|max:120',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(['desc', 'asc', 'alpha-asc', 'alpha-desc'])],
            'per_page' => 'nullable|integer|min:10|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = DB::table($table.' as org');
        if ($allowedAmcos !== null) {
            $query->whereIn('org.uuid', $allowedAmcos);
        }
        if ($type === 'amcos') {
            $query->leftJoin('cooperative_mcus as mcu', 'mcu.uuid', '=', 'org.mcu_uuid')
                ->leftJoin('regions as region', 'region.id', '=', 'org.region_id')
                ->leftJoin('districts as district', 'district.id', '=', 'org.district_id')
                ->select('org.*', 'mcu.name as mcu_name', 'region.name as region_name', 'district.name as district_name');
        } elseif ($type === 'mcus') {
            $query->leftJoin('regions as region', 'region.id', '=', 'org.region_id')
                ->leftJoin('districts as district', 'district.id', '=', 'org.district_id')
                ->leftJoin('countries as country', 'country.id', '=', 'org.country_id')
                ->select('org.*', 'region.name as region_name', 'district.name as district_name', 'country.name as country_name');
        } elseif ($type === 'centres') {
            $query->leftJoin('cooperative_amcos as amcos', 'amcos.uuid', '=', 'org.amcos_uuid')
                ->leftJoin('regions as region', 'region.id', '=', 'org.region_id')
                ->leftJoin('districts as district', 'district.id', '=', 'org.district_id')
                ->select('org.*', 'amcos.name as amcos_name', 'region.name as region_name', 'district.name as district_name');
        } elseif ($type === 'factories') {
            $query->leftJoin('cooperative_mcus as mcu', 'mcu.uuid', '=', 'org.mcu_uuid')
                ->leftJoin('cooperative_amcos as amcos', 'amcos.uuid', '=', 'org.amcos_uuid')
                ->leftJoin('regions as region', 'region.id', '=', 'org.region_id')
                ->leftJoin('districts as district', 'district.id', '=', 'org.district_id')
                ->select('org.*', 'mcu.name as mcu_name', 'amcos.name as amcos_name', 'region.name as region_name', 'district.name as district_name');
        } else {
            $query->select('org.*');
        }
        if (! empty($data['search'])) {
            $query->where(function ($q) use ($data, $type) {
                $term = '%'.trim($data['search']).'%';
                $q->where('org.name', 'like', $term);
                if ($type !== 'centres' && $type !== 'factories') {
                    $q->orWhere('org.registration_number', 'like', $term)
                        ->orWhere('org.tin_number', 'like', $term);
                }
                $q->orWhere('region.name', 'like', $term)
                    ->orWhere('district.name', 'like', $term);
                if ($type === 'centres') {
                    $q->orWhere('amcos.name', 'like', $term);
                }
                if ($type === 'factories') {
                    $q->orWhere('org.registration_number', 'like', $term)
                        ->orWhere('org.tin_number', 'like', $term)
                        ->orWhere('mcu.name', 'like', $term)
                        ->orWhere('amcos.name', 'like', $term);
                }
            });
        }
        if (! empty($data['status'])) {
            $query->where('org.is_active', $data['status'] === 'active');
        }
        [$sort, $direction] = match ($data['sort'] ?? 'desc') {
            'asc' => ['org.created_at', 'asc'],
            'alpha-asc' => ['org.name', 'asc'],
            'alpha-desc' => ['org.name', 'desc'],
            default => ['org.created_at', 'desc'],
        };
        $page = $query->orderBy($sort, $direction)->orderBy('org.id')->paginate($data['per_page'] ?? 10);
        $stats = DB::table($table)->when($allowedAmcos !== null, fn ($q) => $q->whereIn('uuid', $allowedAmcos))->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_active = 1 then 1 else 0 end) as active')->first();
        $extra = match ($type) {
            'mcus' => DB::table('cooperative_amcos')->whereNotNull('mcu_uuid')->count(),
            'amcos' => DB::table('cooperative_amcos')->when($allowedAmcos !== null, fn ($q) => $q->whereIn('uuid', $allowedAmcos))->whereNull('mcu_uuid')->count(),
            'centres' => DB::table($table)->whereNotNull('amcos_uuid')->distinct()->count('amcos_uuid'),
            'factories' => DB::table($table)->whereNotNull('mcu_uuid')->distinct()->count('mcu_uuid'),
        };

        return response()->json([
            'status' => true,
            'data' => $page->items(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'canManage' => $request->user()->platform_role === 'superAdmin',
                'stats' => ['total' => (int) $stats->total, 'active' => (int) $stats->active, 'extra' => $extra],
            ],
        ]);
    }

    public function show(Request $request, string $type, string $uuid): JsonResponse
    {
        abort_unless($type === 'factories', 404);
        // Factory registry reads follow the same module permission as the listing.
        $factory = DB::table('dairy_factories as org')
            ->leftJoin('cooperative_mcus as mcu', 'mcu.uuid', '=', 'org.mcu_uuid')
            ->leftJoin('cooperative_amcos as amcos', 'amcos.uuid', '=', 'org.amcos_uuid')
            ->leftJoin('regions as region', 'region.id', '=', 'org.region_id')
            ->leftJoin('districts as district', 'district.id', '=', 'org.district_id')
            ->leftJoin('wards as ward', 'ward.id', '=', 'org.ward_id')
            ->where('org.uuid', $uuid)
            ->select('org.*', 'mcu.name as mcu_name', 'amcos.name as amcos_name', 'region.name as region_name', 'district.name as district_name', 'ward.name as ward_name')->first();
        abort_unless($factory, 404, 'Factory not found.');
        $canManage = $request->user()->platform_role === 'superAdmin';

        return response()->json([
            'status' => true,
            'data' => $factory,
            'members' => $canManage ? app(FactoryMemberService::class)->list($uuid) : null,
            'meta' => ['canManage' => $canManage],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'superAdmin', 403);

        return response()->json([
            'status' => true,
            'data' => DB::table('cooperative_mcus')->where('is_active', true)
                ->orderBy('name')->get(['uuid', 'name', 'country_id', 'all_regions', 'region_id']),
            'amcos' => DB::table('cooperative_amcos')->where('is_active', true)
                ->orderBy('name')->get(['uuid', 'name']),
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'superAdmin', 403);
        abort_unless(in_array($type, ['mcus', 'amcos', 'centres', 'factories'], true), 404);
        if ($type === 'centres') {
            return $this->storeCentre($request);
        }
        if ($type === 'factories') return $this->saveFactory($request);
        $table = $this->table($type);
        $code = $type === 'mcus' ? 'mcu_code' : ($type === 'factories' ? 'factory_code' : 'amcos_code');
        foreach (['name', 'code', 'registration_number', 'contact_name', 'contact_phone', 'operator_name', 'operator_phone', 'operator_registration_number'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        $organisation = $type !== 'factories';
        if ($organisation) {
            $this->normaliseOrganisation($request);
        }
        $data = $request->validate([
            ...($organisation ? $this->organisationRules($type) : []),
            'uuid' => ['required', 'uuid', Rule::unique($table, 'uuid')],
            'name' => 'required|string|max:255',
            'code' => ['nullable', 'string', 'max:50', Rule::unique($table, $code)],
            ...($organisation ? [] : ['registration_number' => 'nullable|string|max:100']),
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'mcu_uuid' => ['nullable', Rule::exists('cooperative_mcus', 'uuid')->where('is_active', true)],
            'amcos_uuid' => ['nullable', Rule::exists('cooperative_amcos', 'uuid')->where('is_active', true)],
            'operator_type' => ['nullable', Rule::in(['company', 'individual'])],
            'operator_name' => 'nullable|string|max:255',
            'operator_phone' => 'nullable|string|max:50',
            'operator_registration_number' => 'nullable|string|max:100',
            ...($organisation ? [] : ['region_id' => 'nullable|integer', 'district_id' => 'nullable|integer']),
            'address' => 'nullable|string|max:255',
        ]);
        if ($organisation) {
            $data = $this->scopeUnion($type, $data);
            $this->assertLocationChain($data);
        }
        $record = [
            'uuid' => $data['uuid'],
            $code => $data['code'] ?? strtoupper($type === 'mcus' ? 'MCU-' : ($type === 'factories' ? 'FACT-' : 'AMCOS-')).$data['uuid'],
            'name' => $data['name'],
            'registration_number' => $data['registration_number'] ?? null,
            'contact_name' => $data['contact_name'] ?? $data['operator_name'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? $data['operator_phone'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($organisation) {
            foreach (array_keys($this->organisationRules($type)) as $field) {
                $record[$field] = $data[$field] ?? null;
            }
        }
        if ($type === 'amcos') {
            $record['mcu_uuid'] = $data['mcu_uuid'] ?? null;
            $record['default_unit'] = 'l';
        }
        DB::table($table)->insert($record);

        return response()->json(['status' => true, 'data' => $record], 201);
    }

    public function update(Request $request, string $type, string $uuid): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'superAdmin', 403);
        abort_unless(in_array($type, ['mcus', 'amcos', 'centres', 'factories'], true), 404);
        $table = $this->table($type);
        $existing = DB::table($table)->where('uuid', $uuid)->first() ?? abort(404);
        if ($type === 'factories') return $this->saveFactory($request, $existing);
        $organisation = $type === 'mcus' || $type === 'amcos';
        if ($organisation) {
            // Older records can use a different registrar format. Unrelated edits
            // must not revalidate or normalise an identifier that has not changed.
            if (is_string($request->input('registration_number'))
                && trim($request->input('registration_number')) === trim((string) $existing->registration_number)) {
                $request->replace($request->except('registration_number'));
            }
            $this->normaliseOrganisation($request);
        }
        foreach (['name', 'registration_number', 'contact_name', 'contact_phone', 'operator_name', 'operator_phone', 'operator_registration_number'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'contact_name' => 'sometimes|nullable|string|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:50',
            'is_active' => 'sometimes|required|boolean',
        ];
        if ($type === 'factories') {
            $rules['registration_number'] = 'sometimes|nullable|string|max:100';
        }
        if ($organisation) {
            foreach ($this->organisationRules($type) as $field => $rule) {
                $rules[$field] = ['sometimes', ...$rule];
            }
        }
        if ($type === 'factories') {
            $rules['operator_type'] = ['sometimes', Rule::in(['company', 'individual'])];
            $rules['operator_name'] = 'sometimes|nullable|string|max:255';
            $rules['operator_phone'] = 'sometimes|nullable|string|max:50';
            $rules['operator_registration_number'] = 'sometimes|nullable|string|max:100';
            $rules['mcu_uuid'] = 'sometimes|nullable|string';
            $rules['amcos_uuid'] = 'sometimes|nullable|string';
        }
        $data = $request->validate($rules);
        if ($data === []) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }
        if ($organisation) {
            $coverage = array_flip(['country_id', 'all_regions', 'region_id', 'district_id', 'ward_id', 'village_id']);
            if ($type === 'mcus' && array_intersect_key($data, $coverage)) {
                $data = [...$data, ...array_intersect_key($this->scopeUnion($type, [...(array) $existing, ...$data]), $coverage)];
            }
            $this->assertLocationChain([...(array) $existing, ...$data]);
        }
        DB::table($table)->where('uuid', $uuid)->update([...$data, 'updated_at' => now()]);

        return response()->json(['status' => true, 'data' => DB::table($table)->where('uuid', $uuid)->first()]);
    }

    /** Company registration is separate from factory operator/manager memberships. */
    private function saveFactory(Request $request, ?object $existing = null): JsonResponse
    {
        foreach (['name', 'registration_number', 'contact_phone', 'contact_email', 'address'] as $field) {
            if (is_string($request->input($field))) $request->merge([$field => trim($request->input($field))]);
        }
        if ($request->has('tin_number')) $request->merge(['tin_number' => \App\Models\TransporterCompany::normaliseTin($request->input('tin_number'))]);
        $locationChanged = ! $existing || $request->hasAny(['mcu_uuid', 'region_id', 'district_id', 'ward_id']);
        $mcu = DB::table('cooperative_mcus')->where('uuid', $request->input('mcu_uuid', $existing->mcu_uuid ?? null))->first();
        if ($locationChanged && $mcu && ! $mcu->all_regions) {
            // MCU coverage supplies only the region; the factory chooses its own district and ward.
            foreach (['region_id'] as $field) {
                if ($mcu->$field) $request->merge([$field => $mcu->$field]);
            }
        }
        $required = $existing ? 'sometimes|required' : 'required';
        $data = $request->validate([
            ...($existing ? [] : ['uuid' => ['required', 'uuid', Rule::unique('dairy_factories', 'uuid')]]),
            'name' => $required.'|string|max:255',
            'registration_number' => $required.'|string|max:100',
            'tin_number' => [...($existing ? ['sometimes'] : []), 'required', 'string', 'regex:'.\App\Models\TransporterCompany::TIN_PATTERN],
            'dairy_board_license_no' => 'sometimes|nullable|string|max:100',
            'mcu_uuid' => [...($existing ? ['sometimes'] : []), 'required', Rule::exists('cooperative_mcus', 'uuid')->where('is_active', true)],
            'region_id' => $required.'|integer|exists:regions,id',
            'district_id' => $required.'|integer|exists:districts,id',
            'ward_id' => 'sometimes|nullable|integer|exists:wards,id',
            'contact_phone' => $required.'|string|max:50',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|required|boolean',
            'amcos_uuid' => 'prohibited', 'operator_type' => 'prohibited', 'operator_name' => 'prohibited',
            'operator_phone' => 'prohibited', 'operator_registration_number' => 'prohibited', 'contact_name' => 'prohibited',
        ]);
        if ($existing && $data === []) return response()->json(['message' => 'No changes provided.'], 422);
        if ($locationChanged) {
            // Clear old child locations when their parent changes without a replacement.
            if ($existing && isset($data['district_id']) && (int) $data['district_id'] !== (int) $existing->district_id && ! array_key_exists('ward_id', $data)) $data['ward_id'] = null;
            $location = [...(array) ($existing ?? []), ...$data];
            $this->assertLocationChain($location);
            if ($mcu?->country_id && (int) DB::table('regions')->where('id', $location['region_id'])->value('countryId') !== (int) $mcu->country_id) {
                throw ValidationException::withMessages(['region_id' => 'Choose a region in the MCU’s country.']);
            }
        }
        if ($existing) {
            // Legacy staff data is preserved. An explicit MCU assignment replaces an old AMCOS affiliation.
            if (isset($data['mcu_uuid'])) $data['amcos_uuid'] = null;
            DB::table('dairy_factories')->where('uuid', $existing->uuid)->update([...$data, 'updated_at' => now()]);
            $uuid = $existing->uuid;
        } else {
            $uuid = $data['uuid'];
            DB::table('dairy_factories')->insert([
                ...$data, 'factory_code' => 'FACT-'.strtoupper(Str::random(10)), 'amcos_uuid' => null,
                'operator_type' => 'company', 'contact_name' => null,
                'approval_status' => 'approved', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return response()->json(['status' => true, 'data' => DB::table('dairy_factories')->where('uuid', $uuid)->first()], $existing ? 200 : 201);
    }

    /** Registers a collection centre under an AMCOS with an empty milk stock row. */
    private function storeCentre(Request $request): JsonResponse
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $data = $request->validate([
            'uuid' => ['required', 'uuid', Rule::unique('cooperative_collection_centres', 'uuid')],
            'name' => 'required|string|max:255',
            'amcos_uuid' => ['required', Rule::exists('cooperative_amcos', 'uuid')->where('is_active', true)],
            'region_id' => ['required', 'integer', Rule::exists('regions', 'id')],
            'district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
            'ward_id' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'village_id' => ['nullable', 'integer', Rule::exists('villages', 'id')],
        ]);
        $this->assertLocationChain($data);
        $amcos = DB::table('cooperative_amcos')->where('uuid', $data['amcos_uuid'])->first();
        do {
            $code = 'CC-'.strtoupper(Str::random(5));
        } while (DB::table('cooperative_collection_centres')->where('centre_code', $code)->exists());
        $unit = $amcos->default_unit ?? 'l';
        $record = [
            'uuid' => $data['uuid'],
            'mcu_uuid' => $amcos->mcu_uuid,
            'amcos_uuid' => $amcos->uuid,
            'default_unit' => $unit,
            'centre_code' => $code,
            'name' => $data['name'],
            'region_id' => $data['region_id'],
            'district_id' => $data['district_id'],
            'ward_id' => $data['ward_id'] ?? null,
            'village_id' => $data['village_id'] ?? null,
            'opening_balance' => 0,
            'current_balance' => 0,
            'total_collected' => 0,
            'total_dispatched' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::transaction(function () use ($record, $unit) {
            DB::table('cooperative_collection_centres')->insert($record);
            DB::table('cooperative_centre_stocks')->insertOrIgnore([
                'centre_uuid' => $record['uuid'],
                'commodity_code' => 'milk',
                'unit' => $unit,
                'current_balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['status' => true, 'data' => $record], 201);
    }

    /** GET /dairy/cooperatives/locations?level=districts&parent_id=4 */
    public function locations(Request $request): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'superAdmin', 403);
        $data = $request->validate([
            'level' => ['required', Rule::in(array_keys(self::LOCATION_PARENTS))],
            'parent_id' => 'nullable|integer',
        ]);
        $parent = self::LOCATION_PARENTS[$data['level']];
        $rows = DB::table($data['level'])
            ->when($parent && isset($data['parent_id']), fn ($q) => $q->where($parent, $data['parent_id']))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'status' => true,
            'data' => $rows->map(fn ($row) => ['value' => (string) $row->id, 'label' => $row->name])->values(),
        ]);
    }

    /** Registration, tax, website, location, and contact person fields shared by unions and cooperatives. */
    private function organisationRules(string $type): array
    {
        return [
            'registration_number' => ['nullable', 'string', 'max:100', 'regex:'.self::REGISTRATION_PATTERN],
            'tin_number' => ['nullable', 'string', 'regex:/^\d{3}-\d{3}-\d{3}$/'],
            'website' => ['nullable', 'string', 'max:255', 'url'],
            'contact_title' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'string', 'max:255', 'email'],
            'region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'ward_id' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'village_id' => ['nullable', 'integer', Rule::exists('villages', 'id')],
            ...($type === 'mcus' ? [
                'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
                'all_regions' => ['boolean'],
            ] : []),
        ];
    }

    /** A union covers one region of its country, or every region with no office location below the country. */
    private function scopeUnion(string $type, array $data): array
    {
        if ($type !== 'mcus') {
            return $data;
        }
        if (! empty($data['all_regions'])) {
            return [...$data, 'all_regions' => true, 'region_id' => null, 'district_id' => null, 'ward_id' => null, 'village_id' => null];
        }
        if (empty($data['region_id'])) {
            throw ValidationException::withMessages(['region_id' => 'Choose a region, or All regions.']);
        }
        if ((int) DB::table('regions')->where('id', $data['region_id'])->value('countryId') !== (int) ($data['country_id'] ?? 0)) {
            throw ValidationException::withMessages(['region_id' => 'This region does not belong to the selected country.']);
        }

        return [...$data, 'all_regions' => false, 'district_id' => null, 'ward_id' => null, 'village_id' => null];
    }

    private function normaliseOrganisation(Request $request): void
    {
        $clean = fn (string $field) => is_string($request->input($field)) ? trim($request->input($field)) : null;
        $changes = [];
        if (($registration = $clean('registration_number')) !== null) {
            $changes['registration_number'] = $registration === '' ? null : strtoupper(preg_replace('/\s*-\s*/', '-', $registration));
        }
        if (($tin = $clean('tin_number')) !== null) {
            $digits = preg_replace('/\D/', '', $tin);
            $changes['tin_number'] = $tin === '' ? null : (strlen($digits) === 9 ? implode('-', str_split($digits, 3)) : $tin);
        }
        if (($website = $clean('website')) !== null) {
            $changes['website'] = $website === '' ? null : (preg_match('#^https?://#i', $website) ? $website : 'https://'.$website);
        }
        if (($email = $clean('contact_email')) !== null) {
            $changes['contact_email'] = $email === '' ? null : strtolower($email);
        }
        if (($title = $clean('contact_title')) !== null) {
            $changes['contact_title'] = $title === '' ? null : $title;
        }
        $request->merge($changes);
    }

    /** A district must sit in the chosen region, a ward in the district, and a village in the ward. */
    private function assertLocationChain(array $data): void
    {
        $chain = [['district_id', 'districts', 'regionId', 'region_id'], ['ward_id', 'wards', 'districtId', 'district_id'], ['village_id', 'villages', 'wardId', 'ward_id']];
        foreach ($chain as [$field, $table, $parentColumn, $parentField]) {
            if (empty($data[$field])) {
                continue;
            }
            $parent = DB::table($table)->where('id', $data[$field])->value($parentColumn);
            if (empty($data[$parentField]) || (int) $parent !== (int) $data[$parentField]) {
                throw ValidationException::withMessages([$field => 'This location does not belong to the selected parent location.']);
            }
        }
    }
}
