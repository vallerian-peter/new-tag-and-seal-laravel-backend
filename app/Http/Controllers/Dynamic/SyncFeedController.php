<?php

namespace App\Http\Controllers\Dynamic;

use App\Http\Controllers\Controller;
use App\Models\EventRecord;
use App\Models\SyncChange;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Dynamic\SyncChangeRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ordered change feed. The client stores `nextCursor` only after it has saved the
 * whole page, then asks again until `hasMore` is false.
 */
class SyncFeedController extends Controller
{
    public function changes(Request $request, FarmAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'farmUuid' => ['nullable', 'string', 'max:36'],
        ]);
        SyncChangeRecorder::recordRequested();
        $cursor = (int) ($data['cursor'] ?? 0);
        $limit = (int) ($data['limit'] ?? 200);
        $farms = $access->accessibleFarmUuids($request->user());
        if (! empty($data['farmUuid'])) {
            abort_unless($access->canAccess($request->user(), $data['farmUuid']), 403);
            $farms = [$data['farmUuid']];
        }

        $rows = SyncChange::query()
            ->where('id', '>', $cursor)
            ->when($farms !== null, fn ($query) => $query->whereIn('farm_uuid', $farms))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return response()->json([
            'status' => true,
            'data' => $page->filter(function (SyncChange $change) use ($access, $request) {
                if ($change->entity_type !== 'event_record' || $change->action === 'delete') {
                    return true;
                }
                $record = EventRecord::where('uuid', $change->entity_uuid)->first();

                return $record && $access->canReadRecord($request->user(), $record);
            })->map(fn (SyncChange $change) => [
                'cursor' => $change->id,
                'farmUuid' => $change->farm_uuid,
                'entityType' => $change->entity_type,
                'entityUuid' => $change->entity_uuid,
                'action' => $change->action,
                'revision' => $change->revision,
                'data' => $change->data_json,
            ])->values(),
            'nextCursor' => $page->last()?->id ?? $cursor,
            'hasMore' => $hasMore,
        ]);
    }
}
