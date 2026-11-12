<?php

namespace App\Http\Controllers\Dynamic;

use App\Http\Controllers\Controller;
use App\Models\AnimalSubject;
use App\Models\EventDefinition;
use App\Models\EventRecord;
use App\Models\SyncTombstone;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Dynamic\EventSubmissionService;
use App\Services\Dynamic\EventVoidService;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Dynamic\SubjectRegistrationService;
use App\Services\Dynamic\SubjectUpdateService;
use App\Services\Hiring\FarmOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DynamicPlatformController extends Controller
{
    /** Legacy farms use app-generated ids (e.g. "1764580414362-7419169"), not RFC UUIDs. */
    private const FARM_REFERENCE = ['required', 'string', 'max:36'];

    public function manifest(ConfigurationSyncService $configuration): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $configuration->latestManifest()]);
    }

    public function subjects(Request $request, FarmAccessService $access): JsonResponse
    {
        $farmUuid = $request->validate(['farmUuid' => self::FARM_REFERENCE])['farmUuid'];
        abort_unless($access->canAccess($request->user(), $farmUuid), 403);

        return response()->json(['status' => true, 'data' => AnimalSubject::query()->with('identifiers.type')->where('farm_uuid', $farmUuid)->orderByDesc('created_at')->get()]);
    }

    public function registerSubject(Request $request, FarmAccessService $access, SubjectRegistrationService $registration): JsonResponse
    {
        $request->validate([
            'farmUuid' => self::FARM_REFERENCE,
            'blueprintUuid' => ['required', 'uuid'],
            'blueprintVersion' => ['required', 'integer', 'min:1'],
            'unitType' => ['required', 'string'],
            'payload' => ['required', 'array'],
        ]);
        abort_unless($access->can($request->user(), $request->string('farmUuid')->toString(), 'livestock.manage'), 403);
        $subject = $registration->register($request->all(), $request->user());

        return response()->json(['status' => true, 'data' => $subject->load('identifiers.type')], 201);
    }

    public function updateSubject(Request $request, string $subjectUuid, FarmAccessService $access, SubjectUpdateService $updates): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'string', 'max:100'], 'idempotencyUuid' => ['required', 'uuid'],
            'baseRevision' => ['required', 'integer', 'min:1'], 'payload' => ['required', 'array'],
            'registrationContext' => ['nullable', 'array'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'pairedTag' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless($data['uuid'] === $subjectUuid, 422);
        $subject = AnimalSubject::where('uuid', $subjectUuid)->firstOrFail();
        abort_unless($access->can($request->user(), $subject->farm_uuid, 'livestock.manage'), 403);

        return response()->json(['status' => true, 'data' => $updates->update($subject, $data, $request->user())]);
    }

    public function submitEvent(Request $request, string $subjectUuid, FarmAccessService $access, EventSubmissionService $events): JsonResponse
    {
        $request->validate([
            'eventDefinitionUuid' => ['required', 'uuid'],
            'idempotencyUuid' => ['required', 'uuid'],
            'baseRevision' => ['required', 'integer', 'min:1'],
            'occurredAt' => ['nullable', 'date'],
            'payload' => ['required', 'array'],
        ]);
        $subject = AnimalSubject::query()->where('uuid', $subjectUuid)->firstOrFail();
        abort_unless($access->canEvent($request->user(), $subject->farm_uuid, EventDefinition::where('uuid', $request->input('eventDefinitionUuid'))->firstOrFail(), 'create'), 403);
        $record = $events->submit($subject, $request->all(), $request->user());

        return response()->json(['status' => true, 'data' => $record, 'subjectRevision' => $subject->fresh()->revision], 201);
    }

    public function voidEvent(Request $request, string $eventUuid, FarmAccessService $access, EventVoidService $voids): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $record = EventRecord::query()->where('uuid', $eventUuid)->firstOrFail();
        $user = $request->user();
        abort_unless($access->canAccess($user, $record->farm_uuid), 403);
        abort_unless($access->canReadRecord($user, $record) && ($user->hasPlatformPermission('events.void') || (app(FarmOwnership::class)->owns($user, $record->farm_uuid) ? $record->created_by_uuid === $user->uuid : $access->can($user, $record->farm_uuid, 'events.void'))), 403);

        return response()->json(['status' => true, 'data' => $voids->void($record, $user, $data['reason'])]);
    }

    public function events(Request $request, string $subjectUuid, FarmAccessService $access): JsonResponse
    {
        $subject = AnimalSubject::query()->where('uuid', $subjectUuid)->firstOrFail();
        abort_unless($access->canAccess($request->user(), $subject->farm_uuid), 403);

        return response()->json(['status' => true, 'data' => EventRecord::query()->where('subject_uuid', $subjectUuid)->orderByDesc('occurred_at')->get()->filter(fn ($record) => $access->canReadRecord($request->user(), $record))->values()]);
    }

    public function tombstones(Request $request, FarmAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'farmUuid' => self::FARM_REFERENCE,
        ]);
        abort_unless($access->canAccess($request->user(), $data['farmUuid']), 403);
        $query = SyncTombstone::query()
            ->whereIn('entity_type', ['animal_subject', 'event_record'])
            ->whereNull('superseded_at')
            ->orderBy('occurred_at');
        if (! empty($data['since'])) {
            $query->where('occurred_at', '>', $data['since']);
        }
        $query->where('farm_uuid', $data['farmUuid']);

        return response()->json(['status' => true, 'data' => $query->get()]);
    }
}
