<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'countryId' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'regionId' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'farmUuid' => ['nullable', 'uuid', Rule::exists('farms', 'uuid')],
        ]);

        $to = isset($validated['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['to'])->endOfDay()
            : CarbonImmutable::today()->endOfDay();
        $from = isset($validated['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['from'])->startOfDay()
            : $to->subDays(29)->startOfDay();

        return response()->json([
            'status' => true,
            'data' => $this->analytics->build($from, $to, [
                'countryId' => isset($validated['countryId']) ? (int) $validated['countryId'] : null,
                'regionId' => isset($validated['regionId']) ? (int) $validated['regionId'] : null,
                'farmUuid' => $validated['farmUuid'] ?? null,
            ]),
        ]);
    }
}
