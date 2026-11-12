<?php

namespace App\Http\Controllers\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\CareerContext;
use App\Services\EmployerAccessService;
use App\Services\Hiring\HiringNotifications;
use App\Services\Hiring\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CareerApplicationController extends Controller
{
    public function apply(Request $request, int $job): JsonResponse
    {
        app(CareerContext::class)->requireMembership($request, 'jobSeeker');
        $data = $request->validate([
            'coverLetter' => ['nullable', 'string', 'max:10000'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['array'],
            'profileSnapshot' => ['nullable', 'array'],
        ]);

        return DB::transaction(function () use ($request, $job, $data): JsonResponse {
            $record = DB::table('career_jobs')->where('id', $job)->lockForUpdate()->first() ?? abort(404);
            if ((int) $record->owner_user_id === (int) $request->user()->id) {
                return response()->json(['code' => 'CANNOT_APPLY_TO_OWN_JOB'], 403);
            }
            $details = json_decode($record->details, true);
            if ($record->closed || ($record->closes_at && $record->closes_at < today()->toDateString())) {
                return response()->json(['code' => 'JOB_CLOSED'], 409);
            }
            $documents = $data['documents'] ?? $data['profileSnapshot']['documents'] ?? [];
            $letters = collect($documents)->filter(fn ($document) => ($document['type'] ?? '') === 'application_letter');
            if ((! empty($data['coverLetter']) || $letters->isNotEmpty()) && ! ($details['allowsCoverLetter'] ?? false)) {
                return response()->json(['code' => 'COVER_LETTER_NOT_ALLOWED'], 422);
            }
            foreach ($letters as $letter) {
                $bytes = base64_decode($letter['fileData'] ?? '', true);
                $extension = strtolower(pathinfo($letter['fileName'] ?? '', PATHINFO_EXTENSION));
                if ($bytes === false || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024 || ! in_array($extension, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'], true)) {
                    return response()->json(['code' => 'INVALID_APPLICATION_LETTER_ATTACHMENT'], 422);
                }
            }
            if (($details['requiresCoverLetter'] ?? false) && empty(trim($data['coverLetter'] ?? '')) && $letters->isEmpty()) {
                return response()->json(['code' => 'COVER_LETTER_REQUIRED'], 422);
            }
            $profileRaw = DB::table('feature_memberships')->where('user_id', $request->user()->id)->where('feature', 'jobSeeker')->value('details');
            $profile = $profileRaw ? json_decode($profileRaw, true) : [];
            if (! empty($data['profileSnapshot'])) {
                $profile = array_merge($profile ?: [], $data['profileSnapshot']);
            }
            if (array_key_exists('documents', $data)) {
                $profile['documents'] = $data['documents'];
            }

            $inserted = DB::table('career_applications')->insertOrIgnore([
                'job_id' => $job,
                'applicant_user_id' => $request->user()->id,
                'cover_letter' => $data['coverLetter'] ?? null,
                'applicant_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR),
                'status' => 'submitted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $inserted) {
                return response()->json(['code' => 'ALREADY_APPLIED'], 409);
            }
            $application = DB::table('career_applications')->where('job_id', $job)->where('applicant_user_id', $request->user()->id)->first();
            $application->applicant_snapshot = json_decode($application->applicant_snapshot, true);
            $application->onboarding = ($latest = DB::table('career_onboardings')->where('application_id', $application->id)->orderByDesc('id')->value('id')) ? app(OnboardingService::class)->present($latest) : null;
            $notifications = app(HiringNotifications::class);
            $notifications->send("application.$application->id.submitted.applicant", $request->user()->id, $profile['phone'] ?? null, 'application.submitted', "Your application for {$record->title} was submitted.", "Maombi yako ya {$record->title} yametumwa.", ['applicationId' => $application->id]);
            $notifications->send("application.$application->id.submitted.owner", $record->owner_user_id, null, 'application.received', "A new application for {$record->title} is ready to review.", "Maombi mapya ya {$record->title} yamepokelewa.", ['applicationId' => $application->id]);

            return response()->json(['data' => $application], 201);
        });
    }

    public function applications(Request $request, int $job): JsonResponse
    {
        $record = app(CareerContext::class)->job($job);
        abort_unless(app(EmployerAccessService::class)->can($request->user(), (int) $record->owner_user_id, 'applications.view'), 403);

        $applications = DB::table('career_applications')->where('job_id', $job)->get()->map(function ($application) {
            $application->applicant_snapshot = json_decode($application->applicant_snapshot, true);
            $application->onboarding = ($latest = DB::table('career_onboardings')->where('application_id', $application->id)->orderByDesc('id')->value('id')) ? app(OnboardingService::class)->present($latest) : null;

            return $application;
        });

        return response()->json(['data' => $applications]);
    }

    public function updateApplication(Request $request, int $job, int $application): JsonResponse
    {
        $record = app(CareerContext::class)->job($job);
        abort_unless(app(EmployerAccessService::class)->can($request->user(), (int) $record->owner_user_id, 'applications.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['submitted', 'underReview', 'shortlisted', 'interview', 'declined', 'reserve'])]]);

        return DB::transaction(function () use ($job, $application, $data) {
            $query = DB::table('career_applications')->where('job_id', $job)->where('id', $application);
            $row = (clone $query)->lockForUpdate()->first() ?? abort(404);
            abort_if(DB::table('career_onboardings')->where('application_id', $application)->whereIn('status', ['sent', 'confirmed', 'activated'])->exists() || $row->status === 'hired', 409, 'USE_JOINING_WORKFLOW');
            if ($row->status !== $data['status']) {
                $query->update(['status' => $data['status'], 'updated_at' => now()]);
                app(HiringNotifications::class)->send('application.'.$application.'.status.'.Str::uuid(), $row->applicant_user_id, null, 'application.status', 'Your application status is now '.$data['status'].'. Open Tag & Seal.', 'Hali ya maombi yako imesasishwa. Fungua Tag & Seal.', ['applicationId' => $application, 'status' => $data['status']]);
            }

            return response()->json(['data' => (clone $query)->first()]);
        });
    }

    public function myApplications(Request $request): JsonResponse
    {
        app(CareerContext::class)->requireMembership($request, 'jobSeeker');

        $applications = DB::table('career_applications')
            ->join('career_jobs', 'career_applications.job_id', '=', 'career_jobs.id')
            ->where('career_applications.applicant_user_id', $request->user()->id)
            ->select(
                'career_applications.*',
                'career_jobs.title as job_title',
                'career_jobs.details as job_details',
                'career_jobs.closed as job_closed',
                'career_jobs.closes_at as job_closes_at',
                'career_jobs.owner_user_id as job_owner_user_id'
            )
            ->orderByDesc('career_applications.id')
            ->get()
            ->map(function ($app) {
                $app->applicant_snapshot = json_decode($app->applicant_snapshot, true);
                $app->onboarding = ($latest = DB::table('career_onboardings')->where('application_id', $app->id)->orderByDesc('id')->value('id')) ? app(OnboardingService::class)->present($latest) : null;
                $app->job_details = json_decode($app->job_details, true);

                return $app;
            });

        return response()->json(['data' => $applications]);
    }
}
