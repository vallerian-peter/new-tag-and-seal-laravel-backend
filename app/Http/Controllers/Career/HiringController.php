<?php

namespace App\Http\Controllers\Career;

use App\Http\Controllers\Controller;

use App\Services\Dynamic\FarmAccessService;
use App\Services\EmployerAccessService;
use App\Services\Hiring\FarmOwnership;
use App\Services\Hiring\GrantSources;
use App\Services\Hiring\HiringNotifications;
use App\Services\Hiring\OnboardingService;
use App\Services\Hiring\WorkerPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HiringController extends Controller
{
    public function __construct(private OnboardingService $joining, private FarmOwnership $farms) {}

    private function terms(Request $request, bool $manual = false): array
    {
        return $request->validate([
            'requestUuid' => ['required', 'uuid'], 'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'roleTitle' => ['required', 'string', 'max:100'], 'permissions' => ['required', 'array', 'min:1'], 'permissions.*' => ['string', 'distinct'],
            'name' => [$manual ? 'required' : 'nullable', 'string', 'max:255'], 'phone' => [$manual ? 'required' : 'nullable', 'string', 'max:32'],
            'activate' => ['sometimes', 'boolean'],
            'terms' => ['required', 'array'], 'terms.startDate' => ['required', 'date'], 'terms.responseDate' => ['required', 'date'],
            'terms.reportingName' => ['required', 'string', 'max:255'], 'terms.reportingPhone' => ['required', 'string', 'max:32'],
            'terms.instructions' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    public function myAccess(Request $request)
    {
        $access = app(FarmAccessService::class);
        $farms = $access->accessibleFarmUuids($request->user()) ?? DB::table('farms')->pluck('uuid')->all();
        $data = [];
        foreach ($farms as $farm) {
            $permissions = [];
            foreach ([...WorkerPermissions::catalog(), 'events.create'] as $permission) {
                if ($access->can($request->user(), $farm, $permission)) {
                    $permissions[] = $permission;
                }
            }
            $data[$farm] = $permissions;
        }

        return response()->json(['data' => (object) $data, 'farmerIds' => $this->farms->farmerIds($request->user())]);
    }

    public function options(Request $request)
    {
        return response()->json(['data' => ['farms' => $this->farms->farms($request->user()), 'permissions' => WorkerPermissions::catalog(), 'modules' => WorkerPermissions::MODULES]]);
    }

    public function showForApplication(Request $request, int $application)
    {
        $record = DB::table('career_applications')->where('id', $application)->first() ?? abort(404);
        $owner = DB::table('career_jobs')->where('id', $record->job_id)->value('owner_user_id');
        abort_unless((int) $record->applicant_user_id === (int) $request->user()->id || app(EmployerAccessService::class)->can($request->user(), $owner, 'applications.view'), 403);

        return response()->json(['data' => DB::table('career_onboardings')->where('application_id', $application)->orderByDesc('id')->pluck('id')->map(fn ($id) => $this->joining->present($id))]);
    }

    public function show(Request $request, int $onboarding)
    {
        $row = DB::table('career_onboardings')->where('id', $onboarding)->first() ?? abort(404);
        $manage = (int) $row->owner_user_id === (int) $request->user()->id && $this->farms->owns($request->user(), $row->farm_uuid);
        abort_unless($manage || (int) $row->user_id === (int) $request->user()->id, 403);

        return response()->json(['data' => $this->joining->present($onboarding), 'canManage' => $manage]);
    }

    public function send(Request $request, int $application)
    {
        return response()->json(['data' => $this->joining->send($request->user(), $application, $this->terms($request))]);
    }

    public function update(Request $request, int $onboarding)
    {
        return response()->json(['data' => $this->joining->update($request->user(), $onboarding, $this->terms($request))]);
    }

    public function respond(Request $request, int $onboarding)
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'availability' => ['required_if:accept,true', 'nullable', 'string', 'max:1000'], 'arrivalDate' => ['required_if:accept,true', 'nullable', 'date'], 'phone' => ['required_if:accept,true', 'nullable', 'string', 'max:32'], 'message' => ['nullable', 'string', 'max:3000']]);

        return response()->json(['data' => $this->joining->respond($request->user(), $onboarding, $data)]);
    }

    public function activate(Request $request, int $onboarding)
    {
        return response()->json(['data' => $this->joining->activate($request->user(), $onboarding)]);
    }

    public function end(Request $request, int $onboarding)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['cancelled', 'not_joined', 'ended'])], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return response()->json(['data' => $this->joining->end($request->user(), $onboarding, $data['status'], $data['reason'])]);
    }

    public function arrival(Request $request, int $onboarding)
    {
        return DB::transaction(function () use ($request, $onboarding) {
            $row = DB::table('career_onboardings')->where('id', $onboarding)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $row->owner_user_id === (int) $request->user()->id && $this->farms->owns($request->user(), $row->farm_uuid), 403);
            abort_unless(in_array($row->status, ['sent', 'confirmed', 'activated']), 409);
            if (! $row->arrived_at) {
                DB::table('career_onboardings')->where('id', $onboarding)->update(['arrived_at' => now(), 'updated_at' => now()]);
            }

            return response()->json(['data' => $this->joining->present($onboarding)]);
        });
    }

    public function reminder(Request $request, int $onboarding)
    {
        $row = DB::table('career_onboardings')->where('id', $onboarding)->first() ?? abort(404);
        abort_unless((int) $row->owner_user_id === (int) $request->user()->id, 403);
        abort_unless(in_array($row->status, ['sent', 'confirmed', 'draft']), 409);
        app(HiringNotifications::class)->send("joining.$onboarding.reminder.".now()->format('YmdH'), $row->user_id, $row->phone, 'joining.reminder', 'Reminder: review your joining details in Tag & Seal.', 'Kumbusho: kagua taarifa zako za kujiunga kwenye Tag & Seal.', ['onboardingId' => $onboarding]);

        return response()->json(['data' => $this->joining->present($onboarding)]);
    }

    public function workers(Request $request, string $farmUuid)
    {
        abort_unless($this->farms->owns($request->user(), $farmUuid), 403);

        return response()->json(['data' => DB::table('career_onboardings')->where('farm_uuid', $farmUuid)->orderByDesc('id')->pluck('id')->map(fn ($id) => $this->joining->present($id))]);
    }

    public function register(Request $request, string $farmUuid)
    {
        $request->merge(['farmUuid' => $farmUuid]);

        return response()->json(['data' => $this->joining->manual($request->user(), $this->terms($request, true))]);
    }

    public function permissions(Request $request, int $onboarding)
    {
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'distinct']]);
        $permissions = WorkerPermissions::validate($data['permissions']);

        return DB::transaction(function () use ($request, $onboarding, $permissions) {
            $row = DB::table('career_onboardings')->where('id', $onboarding)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $row->owner_user_id === (int) $request->user()->id && $this->farms->owns($request->user(), $row->farm_uuid), 403);
            abort_unless($row->status === 'activated', 409);
            if (json_decode($row->permissions, true) !== $permissions) {
                app(GrantSources::class)->write($row->farm_uuid, $row->user_id, 'placement', (string) $onboarding, $permissions, $request->user()->id);
                DB::table('career_onboardings')->where('id', $onboarding)->update(['permissions' => json_encode($permissions), 'updated_at' => now()]);
                app(HiringNotifications::class)->send("worker.$onboarding.permissions.".(string) Str::uuid(), $row->user_id, $row->phone, 'permissions.updated', 'Your assigned farm permissions have changed. Open Tag & Seal.', 'Ruhusa zako za shamba zimebadilishwa. Fungua Tag & Seal.', ['onboardingId' => $onboarding]);
            }

            return response()->json(['data' => $this->joining->present($onboarding)]);
        });
    }

    public function notifications(Request $request)
    {
        $this->joining->claim($request->user());
        $data = DB::table('hiring_notifications')->where('user_id', $request->user()->id)->orderByDesc('id')->limit(100)->get();
        foreach ($data as $row) {
            $row->payload = json_decode($row->payload, true);
        }

        return response()->json(['data' => $data, 'unread' => DB::table('hiring_notifications')->where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
    }

    public function read(Request $request, int $notification)
    {
        $query = DB::table('hiring_notifications')->where('id', $notification)->where('user_id', $request->user()->id);
        abort_unless((clone $query)->exists(), 404);
        $query->update(['read_at' => now()]);

        return response()->json(['data' => ['id' => $notification]]);
    }
}
