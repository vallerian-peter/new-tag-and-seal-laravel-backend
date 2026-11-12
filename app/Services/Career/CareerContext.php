<?php

namespace App\Services\Career;

use App\Services\Hiring\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CareerContext
{
    public function requireMembership(Request $request, string $feature): void
    {
        abort_unless(DB::table('feature_memberships')->where('user_id', $request->user()->id)->where('feature', $feature)->exists(), 403);
    }

    public function job(int $id): object
    {
        return DB::table('career_jobs')->where('id', $id)->first() ?? abort(404);
    }

    public function present(object $job): object
    {
        $job->details = json_decode($job->details, true);
        $job->occupied_positions = app(OnboardingService::class)->occupied($job->id);
        $job->available_positions = max(0, $job->positions_count - $job->occupied_positions);

        return $job;
    }
}
