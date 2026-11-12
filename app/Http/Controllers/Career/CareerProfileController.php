<?php

namespace App\Http\Controllers\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\CareerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CareerProfileController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        app(CareerContext::class)->requireMembership($request, 'jobSeeker');
        $raw = DB::table('feature_memberships')
            ->where('user_id', $request->user()->id)
            ->where('feature', 'jobSeeker')
            ->value('details');
        $details = $raw ? json_decode($raw, true) : [];

        return response()->json(['data' => $details ?: (object) []]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        app(CareerContext::class)->requireMembership($request, 'jobSeeker');
        $data = $request->validate([
            'details' => ['required', 'array'],
        ]);
        \Illuminate\Support\Facades\Validator::make($data['details'], [
            'fullName' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $existing = DB::table('feature_memberships')
            ->where('user_id', $request->user()->id)
            ->where('feature', 'jobSeeker')
            ->value('details');
        $current = $existing ? json_decode($existing, true) : [];
        $merged = array_merge($current ?: [], $data['details']);
        if (isset($data['details']['fullName']) && trim($data['details']['fullName']) !== '') {
            $merged['name'] = $data['details']['fullName'];
        }

        DB::table('feature_memberships')
            ->where('user_id', $request->user()->id)
            ->where('feature', 'jobSeeker')
            ->update([
                'details' => json_encode($merged, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        return response()->json(['data' => $merged]);
    }
}
