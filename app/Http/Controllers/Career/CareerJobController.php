<?php

namespace App\Http\Controllers\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\CareerContext;
use App\Services\EmployerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CareerJobController extends Controller
{
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => [
            'jobTypes' => DB::table('career_job_types')->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_sw']),
            'options' => DB::table('career_job_options')->where('is_active', true)->orderBy('sort_order')->get(['group', 'code', 'name_en', 'name_sw']),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'jobTypeId' => ['nullable', 'integer', 'exists:career_job_types,id'],
        ]);
        $query = DB::table('career_jobs')->orderByDesc('id');
        if (isset($filters['jobTypeId'])) {
            $query->where('job_type_id', $filters['jobTypeId']);
        }
        if ($request->boolean('mine')) {
            $actor = $request->user();
            $candidateOwners = DB::table('employer_access_grants')->where('member_user_id', $actor->id)->pluck('owner_user_id')->all();
            $candidateOwners[] = $actor->id;
            $access = app(EmployerAccessService::class);
            $owners = array_values(array_filter(array_unique($candidateOwners), fn ($id) => $access->can($actor, (int) $id, 'jobs.view')));
            $query->whereIn('owner_user_id', $owners);
        } else {
            $query->where('closed', false)->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', today()->toDateString()));
        }
        $jobs = $query->limit(100)->get()->map(fn ($job) => app(CareerContext::class)->present($job));

        return response()->json(['data' => $jobs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ownerUserId' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'positionsCount' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'jobTypeId' => ['required', 'integer', Rule::exists('career_job_types', 'id')->where('is_active', true)],
            'closesAt' => ['nullable', 'date', 'after_or_equal:today'],
            'details' => ['required', 'array:description,countryId,regionId,districtId,wardId,villageId,country,region,district,ward,village,address,employerName,jobNature,workingModel,experienceLevel,jobLevel,preferredSex,ageRange,applicationLetterAddress,salaryAmount,startsAt,requirements,responsibilities,benefits,allowsCoverLetter,requiresCoverLetter,imageKey,logoUrl,attachmentUrl'],
            'details.description' => ['required', 'string', 'max:10000'],
            'details.countryId' => ['required', 'integer', 'exists:countries,id'],
            'details.regionId' => ['nullable', 'integer', 'exists:regions,id'],
            'details.districtId' => ['nullable', 'integer', 'exists:districts,id'],
            'details.wardId' => ['nullable', 'integer', 'exists:wards,id'],
            'details.villageId' => ['nullable', 'integer', 'exists:villages,id'],
            'details.employerName' => ['nullable', 'string', 'max:255'],
            'details.jobNature' => ['nullable', 'string', Rule::exists('career_job_options', 'code')->where('group', 'jobNature')->where('is_active', true)],
            'details.workingModel' => ['nullable', 'string', Rule::exists('career_job_options', 'code')->where('group', 'workingModel')->where('is_active', true)],
            'details.experienceLevel' => ['nullable', 'string', Rule::exists('career_job_options', 'code')->where('group', 'experienceLevel')->where('is_active', true)],
            'details.jobLevel' => ['nullable', 'string', 'max:100'],
            'details.preferredSex' => ['nullable', Rule::in(['male', 'female'])],
            'details.ageRange' => ['nullable', 'string', 'max:50'],
            'details.applicationLetterAddress' => ['nullable', 'string', 'max:500'],
            'details.salaryAmount' => ['nullable', 'numeric', 'min:0'],
            'details.startsAt' => ['nullable', 'date'],
            'details.address' => ['nullable', 'string', 'max:500'],
            'details.country' => ['nullable', 'string', 'max:255'],
            'details.region' => ['nullable', 'string', 'max:255'],
            'details.district' => ['nullable', 'string', 'max:255'],
            'details.ward' => ['nullable', 'string', 'max:255'],
            'details.village' => ['nullable', 'string', 'max:255'],
            'details.requirements' => ['nullable', 'array', 'max:50'],
            'details.requirements.*' => ['string', 'max:500'],
            'details.responsibilities' => ['nullable', 'array', 'max:50'],
            'details.responsibilities.*' => ['string', 'max:500'],
            'details.benefits' => ['nullable', 'array', 'max:50'],
            'details.benefits.*' => ['string', 'max:500'],
            'details.allowsCoverLetter' => ['nullable', 'boolean'],
            'details.requiresCoverLetter' => ['nullable', 'boolean'],
            'details.imageKey' => ['nullable', 'string', 'max:255'],
            'details.logoUrl' => ['nullable', 'string', 'max:2048'],
            'details.attachmentUrl' => ['nullable', 'string', 'max:2048'],
        ]);
        $ownerId = (int) ($data['ownerUserId'] ?? $request->user()->id);
        if (! app(EmployerAccessService::class)->can($request->user(), $ownerId, 'jobs.manage')) {
            $hasMembership = DB::table('feature_memberships')->where('user_id', $ownerId)->where('feature', 'jobOwner')->exists();

            return response()->json([
                'code' => $hasMembership ? 'UNAUTHORIZED_ACTION' : 'MISSING_JOB_OWNER_MEMBERSHIP',
                'message' => $hasMembership
                    ? 'You do not have permission to manage jobs for this employer.'
                    : 'You must register as a Job Owner before posting a job vacancy.',
            ], 403);
        }
        $details = $data['details'];
        $membership = DB::table('feature_memberships')
            ->where('user_id', $ownerId)
            ->where('feature', 'jobOwner')
            ->first();
        if (! $membership) {
            return response()->json([
                'code' => 'MISSING_JOB_OWNER_MEMBERSHIP',
                'message' => 'You must register as a Job Owner before posting a job vacancy.',
            ], 403);
        }
        $registeredDetails = json_decode($membership->details, true) ?: [];
        $employerName = trim((string) ($registeredDetails['name'] ?? ''));
        if ($membership->organization_id) {
            $organizationName = DB::table('feature_organizations')
                ->where('id', $membership->organization_id)
                ->where('owner_user_id', $ownerId)
                ->value('name');
            $employerName = trim((string) ($organizationName ?: $employerName));
        }
        if ($employerName === '') {
            return response()->json([
                'code' => 'MISSING_EMPLOYER_NAME',
                'message' => 'The registered Job Owner has no name.',
            ], 422);
        }
        $details['employerName'] = $employerName;
        if (isset($details['regionId']) && ! DB::table('regions')->where('id', $details['regionId'])->where('countryId', $details['countryId'])->exists()) {
            return response()->json(['code' => 'INVALID_REGION', 'message' => 'The region does not belong to the country.'], 422);
        }
        if (isset($details['districtId']) && (! isset($details['regionId']) || ! DB::table('districts')->where('id', $details['districtId'])->where('regionId', $details['regionId'])->exists())) {
            return response()->json(['code' => 'INVALID_DISTRICT', 'message' => 'The district does not belong to the region.'], 422);
        }
        if (isset($details['wardId']) && (! isset($details['districtId']) || ! DB::table('wards')->where('id', $details['wardId'])->where('districtId', $details['districtId'])->exists())) {
            return response()->json(['code' => 'INVALID_WARD', 'message' => 'The ward does not belong to the district.'], 422);
        }
        if (isset($details['villageId']) && (! isset($details['wardId']) || ! DB::table('villages')->where('id', $details['villageId'])->where('wardId', $details['wardId'])->exists())) {
            return response()->json(['code' => 'INVALID_VILLAGE', 'message' => 'The village does not belong to the ward.'], 422);
        }
        if (($details['requiresCoverLetter'] ?? false) && ! ($details['allowsCoverLetter'] ?? false)) {
            return response()->json(['code' => 'INVALID_COVER_LETTER_RULE', 'message' => 'A required cover letter must be allowed.'], 422);
        }
        if (($details['allowsCoverLetter'] ?? false) && trim((string) ($details['applicationLetterAddress'] ?? '')) === '') {
            return response()->json(['code' => 'APPLICATION_LETTER_ADDRESS_REQUIRED', 'message' => 'Enter the address for application letters.'], 422);
        }
        if (! ($details['allowsCoverLetter'] ?? false)) {
            unset($details['applicationLetterAddress']);
        }
        $id = DB::table('career_jobs')->insertGetId([
            'owner_user_id' => $ownerId, 'positions_count' => $data['positionsCount'] ?? 1, 'job_type_id' => $data['jobTypeId'], 'title' => $data['title'],
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'closes_at' => $data['closesAt'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => app(CareerContext::class)->present(app(CareerContext::class)->job($id))], 201);
    }

    public function close(Request $request, int $job): JsonResponse
    {
        $record = app(CareerContext::class)->job($job);
        abort_unless(app(EmployerAccessService::class)->can($request->user(), (int) $record->owner_user_id, 'jobs.manage'), 403);
        DB::table('career_jobs')->where('id', $job)->update(['closed' => true, 'updated_at' => now()]);

        return response()->json(['data' => app(CareerContext::class)->present(app(CareerContext::class)->job($job))]);
    }
}
