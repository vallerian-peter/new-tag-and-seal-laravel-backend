<?php

namespace App\Http\Controllers\Dynamic;

use App\Http\Controllers\Controller;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Dynamic\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly FarmAccessService $access,
    ) {}

    public function animals(Request $request): JsonResponse
    {
        return $this->respond($this->reports->animals($this->scope($request)));
    }

    public function events(Request $request): JsonResponse
    {
        return $this->respond($this->reports->events($this->scope($request)));
    }

    public function reproduction(Request $request): JsonResponse
    {
        return $this->respond($this->reports->reproduction($this->scope($request)));
    }

    public function production(Request $request): JsonResponse
    {
        return $this->respond($this->reports->production($this->scope($request)));
    }

    public function health(Request $request): JsonResponse
    {
        return $this->respond($this->reports->health($this->scope($request)));
    }

    private function scope(Request $request): array
    {
        $data = $request->validate([
            'farmUuid' => ['nullable', 'string', 'max:36'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $farms = $this->access->accessibleFarmUuids($request->user());
        if (! empty($data['farmUuid'])) {
            abort_unless($this->access->canAccess($request->user(), $data['farmUuid']), 403);
            $farms = [$data['farmUuid']];
        }

        return ['farms' => $farms, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null];
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $data]);
    }
}
