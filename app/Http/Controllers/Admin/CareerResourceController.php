<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Hiring\HiringNotifications;
use App\Services\Hiring\OnboardingService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CareerResourceController extends Controller
{
    public const RESOURCES = ['jobs', 'applications', 'job-seekers', 'employers', 'memberships', 'onboardings', 'workers', 'job-types', 'job-options'];
    private const REVIEW = ['submitted', 'underReview', 'shortlisted', 'interview', 'reserve', 'declined'];

    public function overview(): JsonResponse
    {
        $applications = DB::table('career_applications')->selectRaw('status, count(*) as total')->groupBy('status')->get();
        $joining = DB::table('career_onboardings')->selectRaw('status, count(*) as total')->groupBy('status')->get();
        return response()->json(['data' => [
            'stats' => [
                ['label' => 'Open vacancies', 'value' => DB::table('career_jobs')->where('closed', false)->where(fn ($q) => $q->whereNull('closes_at')->orWhereDate('closes_at', '>=', today()))->count(), 'href' => 'jobs'],
                ['label' => 'Applications', 'value' => DB::table('career_applications')->count(), 'href' => 'applications'],
                ['label' => 'Joining in progress', 'value' => DB::table('career_onboardings')->whereIn('status', ['sent', 'confirmed'])->count(), 'href' => 'onboardings'],
                ['label' => 'Active workers', 'value' => DB::table('farm_worker_assignments')->where('status', 'active')->count(), 'href' => 'workers'],
            ],
            'applications' => $applications, 'joining' => $joining,
            'memberships' => DB::table('feature_memberships')->selectRaw('feature, count(*) as total')->groupBy('feature')->get(),
        ]]);
    }

    private function query(string $resource): Builder
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        if (in_array($resource, ['job-types', 'job-options'])) return DB::table($resource === 'job-types' ? 'career_job_types as r' : 'career_job_options as r')->select('r.*');
        if ($resource === 'jobs') {
            return DB::table('career_jobs as r')->leftJoin('users as u', 'u.id', '=', 'r.owner_user_id')->leftJoin('phone_identities as p', 'p.user_id', '=', 'u.id')
                ->leftJoin('career_job_types as c', 'c.id', '=', 'r.job_type_id')->leftJoin('farms as f', 'f.uuid', '=', 'r.farm_uuid')
                ->leftJoin('feature_organizations as o', 'o.owner_user_id', '=', 'u.id')
                ->select('r.*', 'u.username as account_name', 'u.email as owner_email', 'p.display_name as verified_name', 'p.phone as phone', 'c.name_en as category', 'f.name as farm_name', 'o.name as organization_name')
                ->selectSub(DB::table('career_applications')->whereColumn('job_id', 'r.id')->selectRaw('count(*)'), 'applications_count');
        }
        if ($resource === 'applications') {
            return DB::table('career_applications as r')->join('career_jobs as j', 'j.id', '=', 'r.job_id')->join('users as u', 'u.id', '=', 'r.applicant_user_id')
                ->leftJoin('phone_identities as p', 'p.user_id', '=', 'u.id')->join('users as e', 'e.id', '=', 'j.owner_user_id')
                ->leftJoin('career_job_types as c', 'c.id', '=', 'j.job_type_id')
                ->leftJoin('farms as f', 'f.uuid', '=', 'j.farm_uuid')
                ->select('r.*', 'j.title as job_title', 'j.details as job_details', 'c.name_en as job_category', 'f.name as farm_name', 'e.username as employer_name', 'u.username as account_name', 'p.phone as phone', 'p.display_name as verified_name');
        }
        if (in_array($resource, ['job-seekers', 'employers', 'memberships'])) {
            $query = DB::table('feature_memberships as r')->join('users as u', 'u.id', '=', 'r.user_id')->leftJoin('phone_identities as p', 'p.user_id', '=', 'u.id')
                ->leftJoin('feature_organizations as o', 'o.id', '=', 'r.organization_id')->select('r.*', 'u.username as account_name', 'u.status as account_status', 'u.role as account_role', 'p.phone as phone', 'p.display_name as verified_name', 'o.name as organization_name');
            if ($resource === 'job-seekers') $query->where('r.feature', 'jobSeeker');
            if ($resource === 'employers') $query->where('r.feature', 'jobOwner');
            return $query;
        }
        $query = DB::table('career_onboardings as r')->leftJoin('farms as f', 'f.uuid', '=', 'r.farm_uuid')->join('users as e', 'e.id', '=', 'r.owner_user_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')->select('r.*', 'f.name as farm_name', 'e.username as employer_name', 'u.username as account_name')
            ->selectSub(DB::table('farm_worker_assignments as a')->whereColumn('a.onboarding_id', 'r.id')->select('a.status')->limit(1), 'access_status');
        if ($resource === 'workers') $query->whereExists(fn ($q) => $q->selectRaw('1')->from('farm_worker_assignments as a')->whereColumn('a.onboarding_id', 'r.id'));
        return $query;
    }

    private function present(object $row, string $resource): object
    {
        foreach (['details', 'applicant_snapshot', 'terms', 'permissions', 'response'] as $key) {
            if (isset($row->$key) && is_string($row->$key)) $row->$key = json_decode($row->$key, true) ?: [];
        }
        $row->name = $row->name ?? $row->applicant_snapshot['fullName'] ?? $row->details['fullName'] ?? $row->details['name'] ?? $row->verified_name ?? $row->account_name ?? $row->name_en ?? '';
        if (in_array($resource, ['jobs', 'job-types', 'job-options'])) $row->name = $row->title ?? $row->name_en;
        if ($resource === 'jobs') {
            $row->closed = (bool) $row->closed;
            $row->occupied_positions = app(OnboardingService::class)->occupied($row->id);
            $row->available_positions = max(0, $row->positions_count - $row->occupied_positions);
            $row->status = $row->closed ? 'closed' : ($row->closes_at && substr($row->closes_at, 0, 10) < today()->toDateString() ? 'expired' : 'active');

            $rawEmployer = $row->details['employerName'] ?? null;
            $orgName = $row->organization_name ?? null;
            $displayName = $rawEmployer ?: ($orgName ?: ($row->verified_name ?: ($row->account_name ?: 'Employer #'.$row->owner_user_id)));

            $subId = [];
            if (!empty($row->farm_name)) $subId[] = 'Farm: '.$row->farm_name;
            if (!empty($row->phone)) $subId[] = $row->phone;
            elseif (!empty($row->account_name) && $row->account_name !== $displayName) $subId[] = '@'.$row->account_name;

            $row->employer_name = $displayName;
            $row->employer_identification = implode(' · ', $subId) ?: ($row->farm_name ?? 'Registered Employer');
        }
        if ($resource === 'applications') {
            $id = DB::table('career_onboardings')->where('application_id', $row->id)->orderByDesc('id')->value('id');
            $row->onboarding = $id ? app(OnboardingService::class)->present($id) : null;
            $row->joining_status = $row->onboarding['status'] ?? null;

            $snap = $row->applicant_snapshot ?? [];
            $row->candidate_name = $snap['fullName'] ?? $row->verified_name ?? $row->account_name ?? 'Candidate #'.$row->applicant_user_id;
            $row->candidate_phone = $snap['phone'] ?? $row->phone ?? null;
            $row->candidate_nida = $snap['nidaNumber'] ?? $snap['identityNumber'] ?? null;
            $row->candidate_location = implode(', ', array_filter([$snap['district'] ?? null, $snap['region'] ?? null])) ?: ($snap['country'] ?? null);
            $row->candidate_gender = $snap['gender'] ?? null;
            $row->job_with_category = $row->job_title.(!empty($row->job_category) ? ' · '.$row->job_category : '');
        }
        if (in_array($resource, ['job-seekers', 'employers', 'memberships'])) {
            $row->status = $row->account_status;
            $row->phone_verified = $row->phone !== null;
            $row->registered_farms = $row->farmer_id ? DB::table('farms')->where('farmerId', $row->farmer_id)->count() : 0;
            if ($resource === 'job-seekers') {
                $details = $row->details ?? [];
                $row->candidate_name = $details['fullName'] ?? $details['name'] ?? $row->verified_name ?? $row->account_name ?? 'Seeker #'.$row->user_id;
                $row->candidate_phone = $details['phone'] ?? $row->phone ?? null;
                $row->candidate_nida = $details['nidaNumber'] ?? $details['identityNumber'] ?? null;
                $row->candidate_location = implode(', ', array_filter([$details['district'] ?? null, $details['region'] ?? null])) ?: ($details['country'] ?? null);
                $row->candidate_gender = $details['gender'] ?? null;
                $row->preferred_role = $details['preferredRole'] ?? $details['roleTitle'] ?? null;
                $skills = $details['skills'] ?? [];
                $row->skills_summary = is_array($skills) ? implode(', ', array_slice($skills, 0, 3)) : (is_string($skills) ? $skills : null);
            }
            if ($resource === 'employers') {
                $row->total_jobs = DB::table('career_jobs')->where('owner_user_id', $row->user_id)->count();
                $row->registered_farms = DB::table('farms')->whereIn('farmerId', DB::table('feature_memberships')->where('user_id', $row->user_id)->where('feature', 'farmer')->whereNotNull('farmer_id')->select('farmer_id'))->count();
            }
        }
        if (in_array($resource, ['job-types', 'job-options'])) { $row->is_active = (bool) $row->is_active; $row->status = $row->is_active ? 'active' : 'inactive'; }
        if (in_array($resource, ['onboardings', 'workers'])) {
            $row->overdue = app(OnboardingService::class)->present($row->id)['overdue'];
            $row->phone_verified = $row->user_id && DB::table('phone_identities')->where('user_id', $row->user_id)->exists();
        }
        return $row;
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', 'string', 'max:50'], 'feature' => ['nullable', 'string', 'max:30'], 'group' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->query($resource);
        $total = (clone $query)->count();
        if ($search = trim($data['search'] ?? '')) {
            $columns = match ($resource) {
                'jobs' => ['r.title', 'r.details', 'u.username', 'c.name_en'],
                'applications' => ['j.title', 'u.username', 'p.phone', 'r.applicant_snapshot'],
                'job-seekers', 'employers', 'memberships' => ['u.username', 'p.phone', 'r.details', 'o.name'],
                'job-types' => ['r.code', 'r.name_en', 'r.name_sw'],
                'job-options' => ['r.code', 'r.name_en', 'r.name_sw', 'r.group'],
                default => ['r.name', 'r.role_title', 'r.phone', 'f.name', 'e.username'],
            };
            $query->where(function ($q) use ($columns, $search) { foreach ($columns as $column) $q->orWhere($column, 'like', '%'.$search.'%'); });
        }
        $status = $data['status'] ?? 'all';
        if ($status !== 'all') {
            if ($resource === 'jobs') {
                $query->where('r.closed', $status === 'closed');
                if ($status === 'active') $query->where(fn ($q) => $q->whereNull('r.closes_at')->orWhereDate('r.closes_at', '>=', today()));
                if ($status === 'expired') $query->whereDate('r.closes_at', '<', today());
            } elseif (in_array($resource, ['job-types', 'job-options'])) $query->where('r.is_active', $status === 'active');
            elseif (in_array($resource, ['job-seekers', 'employers', 'memberships'])) $query->where('u.status', $status);
            else $query->where('r.status', $status);
        }
        if ($resource === 'memberships' && !empty($data['feature']) && $data['feature'] !== 'all') $query->where('r.feature', $data['feature']);
        if ($resource === 'job-options' && !empty($data['group']) && $data['group'] !== 'all') $query->where('r.group', $data['group']);
        $records = $query->orderBy('r.id', $data['sort'] ?? 'desc')->paginate($data['per_page'] ?? 10);
        return response()->json(['data' => $records->getCollection()->map(fn ($row) => $this->present($row, $resource)), 'meta' => [
            'currentPage' => $records->currentPage(), 'perPage' => $records->perPage(), 'lastPage' => $records->lastPage(), 'total' => $records->total(),
            'stats' => $this->stats($resource, $total),
        ]]);
    }

    private function stats(string $resource, int $total): array
    {
        $members = fn (string $feature) => DB::table('feature_memberships as m')->where('m.feature', $feature);
        $pairs = match ($resource) {
            'jobs' => [['Vacancies', $total], ['Open vacancies', DB::table('career_jobs')->where('closed', false)->where(fn ($q) => $q->whereNull('closes_at')->orWhereDate('closes_at', '>=', today()))->count()], ['Applications', DB::table('career_applications')->count()]],
            'applications' => [['Applications', $total], ['On reserve', DB::table('career_applications')->where('status', 'reserve')->count()], ['Activated hires', DB::table('career_applications')->where('status', 'hired')->count()]],
            'job-seekers' => [['Job seeker profiles', $total], ['Verified accounts', $members('jobSeeker')->join('phone_identities as p', 'p.user_id', '=', 'm.user_id')->count()], ['Applications submitted', DB::table('career_applications')->count()]],
            'employers' => [['Employer memberships', $total], ['Organizations', $members('jobOwner')->whereNotNull('m.organization_id')->count()], ['Posted vacancies', DB::table('career_jobs')->count()]],
            'memberships' => [['Service memberships', $total], ['Member accounts', DB::table('feature_memberships')->distinct()->count('user_id')], ['Farmer relationships', DB::table('feature_memberships')->whereNotNull('farmer_id')->count()]],
            'onboardings' => [['Joining records', $total], ['Awaiting replies', DB::table('career_onboardings')->where('status', 'sent')->count()], ['Awaiting activation', DB::table('career_onboardings')->where('status', 'confirmed')->count()]],
            'workers' => [['Worker assignments', $total], ['Active workers', DB::table('farm_worker_assignments')->where('status', 'active')->count()], ['Pending assignments', DB::table('farm_worker_assignments')->where('status', 'pending')->count()]],
            default => [['Catalog records', $total], ['Active choices', $this->query($resource)->where('r.is_active', true)->count()], ['Inactive choices', $this->query($resource)->where('r.is_active', false)->count()]],
        };
        return array_map(fn ($pair) => ['label' => $pair[0], 'value' => $pair[1], 'note' => 'Across this registry'], $pairs);
    }

    public function show(string $resource, string $record): JsonResponse
    {
        $row = $this->query($resource)->where('r.id', $record)->first() ?? abort(404);
        $row = $this->present($row, $resource);
        if (in_array($resource, ['job-seekers', 'memberships', 'employers'])) {
            $row->memberships = DB::table('feature_memberships')->where('user_id', $row->user_id)->get(['id', 'feature', 'organization_id', 'farmer_id', 'created_at']);
        }
        if (in_array($resource, ['onboardings', 'workers'])) $row->history = DB::table('career_onboardings')->where('user_id', $row->user_id)->where('farm_uuid', $row->farm_uuid)->orderByDesc('id')->get(['id', 'source', 'role_title', 'status', 'activated_at', 'ended_at', 'termination_reason']);
        return response()->json(['data' => $row]);
    }

    public function options(string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        return response()->json(['data' => ['applicationStatuses' => self::REVIEW, 'groups' => DB::table('career_job_options')->distinct()->orderBy('group')->pluck('group'), 'features' => ['farmer', 'jobSeeker', 'jobOwner', 'milking', 'shop'], 'farms' => $resource === 'workers' ? $this->workerFarms() : []]]);
    }

    private function workerFarms(): array
    {
        $relations = DB::table('feature_memberships')->where('feature', 'farmer')->whereNotNull('farmer_id')->get(['farmer_id', 'user_id']);
        $links = [];
        foreach ($relations as $row) $links[$row->farmer_id][] = $row->user_id;
        foreach (DB::table('users')->where('role', 'farmer')->get(['id', 'roleId']) as $row) $links[$row->roleId][] = $row->id;
        $result = [];
        foreach (DB::table('farms')->where('status', 'active')->orderBy('name')->get(['uuid', 'name', 'farmerId']) as $farm) {
            $ids = array_unique($links[$farm->farmerId] ?? []);
            if (count($ids) !== 1) continue;
            $owner = \App\Models\User::find(array_values($ids)[0]);
            if (!$owner || !app(\App\Services\Hiring\FarmOwnership::class)->owns($owner, $farm->uuid)) continue;
            $result[] = ['value' => $farm->uuid, 'label' => $farm->name.' · '.$owner->username, 'ownerId' => $owner->id];
        }
        return $result;
    }

    private function catalogData(Request $request, string $resource, ?int $id = null): array
    {
        $table = $resource === 'job-types' ? 'career_job_types' : 'career_job_options';
        $unique = Rule::unique($table, 'code')->ignore($id);
        if ($resource === 'job-options') $unique->where('group', $request->input('group'));
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.-]+$/', $unique],
            'name_en' => ['required', 'string', 'max:255'], 'name_sw' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'], 'is_active' => ['required', 'boolean'],
            'group' => [$resource === 'job-options' ? 'required' : 'exclude', 'string', Rule::in(DB::table('career_job_options')->distinct()->pluck('group')->all())],
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        return $data;
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        if ($resource === 'workers') {
            $data = $request->validate(['requestUuid' => ['required', 'uuid'], 'farmUuid' => ['required', 'string'], 'name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:32'], 'roleTitle' => ['required', 'string', 'max:100'], 'terms' => ['required', 'array'], 'terms.startDate' => ['required', 'date'], 'terms.responseDate' => ['required', 'date'], 'terms.reportingName' => ['required', 'string', 'max:255'], 'terms.reportingPhone' => ['required', 'string', 'max:32'], 'terms.instructions' => ['nullable', 'string', 'max:3000'], 'activate' => ['prohibited'], 'permissions' => ['prohibited']]);
            $farm = collect($this->workerFarms())->firstWhere('value', $data['farmUuid']);
            abort_unless($farm, 422, 'Select an active farm with one verified owner relationship.');
            $owner = \App\Models\User::findOrFail($farm['ownerId']);
            $row = app(OnboardingService::class)->manual($owner, [...$data, 'permissions' => ['farm.view']], $request->user());
            return $this->show('workers', (string) $row['id'])->setStatusCode(201);
        }
        abort_unless(in_array($resource, ['job-types', 'job-options']), 405);
        $data = $this->catalogData($request, $resource);
        $table = $resource === 'job-types' ? 'career_job_types' : 'career_job_options';
        $id = DB::table($table)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $resource, $id, $data);
        return $this->show($resource, (string) $id)->setStatusCode(201);
    }

    public function update(Request $request, string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        if (in_array($resource, ['job-types', 'job-options'])) {
            $data = $this->catalogData($request, $resource, (int) $record);
            $table = $resource === 'job-types' ? 'career_job_types' : 'career_job_options';
            abort_unless(DB::table($table)->where('id', $record)->exists(), 404);
            DB::transaction(function () use ($table, $record, $data, $request, $resource) {
                DB::table($table)->where('id', $record)->update([...$data, 'updated_at' => now()]);
                $this->audit($request, $resource, (int) $record, $data);
            });
            return $this->show($resource, $record);
        }
        if ($resource === 'jobs') {
            $data = $request->validate(['closed' => ['sometimes', 'boolean'], 'closes_at' => ['nullable', 'date'], 'positions_count' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
            DB::transaction(function () use ($request, $record, $data) {
                DB::table('career_jobs')->where('id', $record)->lockForUpdate()->first() ?? abort(404);
                abort_if(isset($data['positions_count']) && $data['positions_count'] < app(OnboardingService::class)->occupied((int) $record), 422, 'Capacity cannot be lower than reserved and hired positions.');
                DB::table('career_jobs')->where('id', $record)->update([...$data, 'updated_at' => now()]);
                $this->audit($request, 'jobs', (int) $record, $data);
            });
            return $this->show($resource, $record);
        }
        if ($resource === 'job-seekers') {
            $data = $request->validate([
                'status' => ['sometimes', 'string', Rule::in(['active', 'not-active'])],
                'preferredRole' => ['sometimes', 'nullable', 'string', 'max:255'],
                'region' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);
            DB::transaction(function () use ($request, $record, $data) {
                $row = DB::table('feature_memberships')->where('id', $record)->where('feature', 'jobSeeker')->first() ?? abort(404);
                $currentDetails = json_decode($row->details ?? '{}', true) ?: [];
                if (isset($data['preferredRole'])) $currentDetails['preferredRole'] = $data['preferredRole'];
                if (isset($data['region'])) $currentDetails['region'] = $data['region'];
                DB::table('feature_memberships')->where('id', $record)->update(['details' => json_encode($currentDetails), 'updated_at' => now()]);
                if (isset($data['status'])) {
                    DB::table('users')->where('id', $row->user_id)->update(['status' => $data['status'] === 'active' ? 'active' : 'notActive']);
                }
                $this->audit($request, 'job-seekers', (int) $record, $data);
            });
            return $this->show($resource, $record);
        }
        abort_unless($resource === 'applications', 405, 'Joining and farm access require the registered farm owner’s approval. Memberships do not change account credentials or worker permissions.');
        $data = $request->validate(['status' => ['required', Rule::in(self::REVIEW)]]);
        DB::transaction(function () use ($request, $record, $data) {
            $row = DB::table('career_applications')->where('id', $record)->lockForUpdate()->first() ?? abort(404);
            abort_if($row->status === 'hired' || DB::table('career_onboardings')->where('application_id', $record)->whereIn('status', OnboardingService::OCCUPIED)->exists(), 409, 'Use the owner-approved joining workflow for this placement.');
            if ($row->status === $data['status']) return;
            DB::table('career_applications')->where('id', $record)->update([...$data, 'updated_at' => now()]);
            app(HiringNotifications::class)->send('application.'.$record.'.status.'.Str::uuid(), $row->applicant_user_id, null, 'application.status', 'Your application status has changed. Open Tag & Seal.', 'Hali ya maombi yako imebadilishwa. Fungua Tag & Seal.', ['applicationId' => (int) $record, ...$data]);
            $this->audit($request, 'applications', (int) $record, $data);
        });
        return $this->show($resource, $record);
    }

    public function destroy(Request $request, string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);
        DB::transaction(function () use ($resource, $record, $request) {
            if ($resource === 'job-types') {
                $item = DB::table('career_job_types')->where('id', $record)->first() ?? abort(404);
                abort_if(DB::table('career_jobs')->where('job_type_id', $record)->exists(), 422, 'Cannot delete job category because vacancies are associated with it.');
                DB::table('career_job_types')->where('id', $record)->delete();
                $this->audit($request, $resource, (int) $record, ['deleted' => true, 'code' => $item->code]);
            } elseif ($resource === 'job-options') {
                $item = DB::table('career_job_options')->where('id', $record)->first() ?? abort(404);
                DB::table('career_job_options')->where('id', $record)->delete();
                $this->audit($request, $resource, (int) $record, ['deleted' => true, 'code' => $item->code]);
            } elseif ($resource === 'jobs') {
                $item = DB::table('career_jobs')->where('id', $record)->first() ?? abort(404);
                DB::table('career_jobs')->where('id', $record)->delete();
                $this->audit($request, $resource, (int) $record, ['deleted' => true, 'title' => $item->title]);
            } elseif ($resource === 'applications') {
                $item = DB::table('career_applications')->where('id', $record)->first() ?? abort(404);
                DB::table('career_applications')->where('id', $record)->delete();
                $this->audit($request, $resource, (int) $record, ['deleted' => true]);
            } elseif (in_array($resource, ['job-seekers', 'employers', 'memberships'], true)) {
                $item = DB::table('feature_memberships')->where('id', $record)->first() ?? abort(404);
                DB::table('feature_memberships')->where('id', $record)->delete();
                $this->audit($request, $resource, (int) $record, ['deleted' => true, 'feature' => $item->feature]);
            } elseif ($resource === 'onboardings') {
                $item = DB::table('career_onboardings')->where('id', $record)->first() ?? abort(404);
                DB::table('career_onboardings')->where('id', $record)->delete();
                $this->audit($request, $resource, (int) $record, ['deleted' => true]);
            } elseif ($resource === 'workers') {
                $item = DB::table('farm_worker_assignments')->where('id', $record)->first() ?? abort(404);
                DB::table('farm_worker_assignments')->where('id', $record)->delete();
                $this->audit($request, $resource, (int) $record, ['deleted' => true]);
            }
        });
        return response()->json(['status' => true, 'message' => 'Record deleted successfully.']);
    }

    private function audit(Request $request, string $resource, int $id, array $data): void
    {
        DB::table('audit_logs')->insert(['uuid' => (string) Str::uuid(), 'actor_uuid' => $request->user()->uuid, 'action' => 'career.admin.updated', 'entity_type' => $resource, 'entity_uuid' => (string) $id, 'after_json' => json_encode($data), 'request_uuid' => (string) Str::uuid(), 'created_at' => now()]);
    }
}
