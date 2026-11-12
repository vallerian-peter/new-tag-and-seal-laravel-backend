<?php

namespace App\Http\Controllers\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\CareerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CareerSavedJobController extends Controller
{
    public function save(Request $request, int $job): JsonResponse
    {
        app(CareerContext::class)->requireMembership($request, 'jobSeeker');
        app(CareerContext::class)->job($job);
        DB::table('career_saved_jobs')->updateOrInsert(['user_id' => $request->user()->id, 'job_id' => $job]);

        return response()->json(['saved' => true]);
    }

    public function saved(Request $request): JsonResponse
    {
        app(CareerContext::class)->requireMembership($request, 'jobSeeker');
        $jobs = DB::table('career_jobs')->join('career_saved_jobs', 'career_jobs.id', '=', 'career_saved_jobs.job_id')
            ->where('career_saved_jobs.user_id', $request->user()->id)->select('career_jobs.*')->get()
            ->map(fn ($job) => app(CareerContext::class)->present($job));

        return response()->json(['data' => $jobs]);
    }

    public function unsave(Request $request, int $job): JsonResponse
    {
        app(CareerContext::class)->requireMembership($request, 'jobSeeker');
        DB::table('career_saved_jobs')->where('user_id', $request->user()->id)->where('job_id', $job)->delete();

        return response()->json(['saved' => false]);
    }
}
