<?php

namespace App\Services\Dynamic;

use App\Models\AnimalSubject;
use App\Models\EventRecord;
use App\Models\SyncChange;
use App\Models\SubjectIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Appends to the server change feed. Called from model events, so each entry is
 * written in the same transaction as the change it describes.
 */
class SyncChangeRecorder
{
    public static function subject(AnimalSubject $subject, ?string $action = null, ?int $revision = null): void
    {
        if ($subject->wasChanged('farm_uuid') && ($previous = $subject->getOriginal('farm_uuid'))) {
            self::append($previous, 'animal_subject', $subject->uuid, 'delete', $subject->revision, null);
        }
        $data = $subject->attributesToArray();
        $data['identifiers'] = SubjectIdentifier::query()->with('type')->where('subject_uuid', $subject->uuid)->where('status', 'active')->get()->toArray();
        $action ??= $subject->trashed() ? 'delete' : 'upsert';
        $revision ??= $subject->revision;
        self::append($subject->farm_uuid, 'animal_subject', $subject->uuid, $action, $revision, array_replace($data, ['revision' => $revision]));
    }

    public static function event(EventRecord $record): void
    {
        self::append($record->farm_uuid, 'event_record', $record->uuid, 'upsert', $record->revision, $record->attributesToArray());
    }

    /** For rows written without Eloquent (the legacy upgrade). */
    public static function eventByUuid(string $uuid): void
    {
        if ($record = EventRecord::query()->where('uuid', $uuid)->first()) {
            self::event($record);
        }
    }

    public static function identifier(SubjectIdentifier $identifier): void
    {
        if ($subject = AnimalSubject::withTrashed()->where('uuid', $identifier->subject_uuid)->first()) {
            self::subject($subject);
        }
    }

    /** Appends feed entries for changes the Portal queued; returns how many were recorded. */
    public static function recordRequested(): int
    {
        if (! DB::table('sync_change_requests')->whereNull('recorded_at')->exists()) {
            return 0;
        }

        return DB::transaction(function (): int {
            $requests = DB::table('sync_change_requests')->whereNull('recorded_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($requests as $request) {
                if ($request->entity_type === 'event_record') {
                    self::eventByUuid($request->entity_uuid);
                } elseif ($request->entity_type === 'animal_subject' && $subject = AnimalSubject::withTrashed()->where('uuid', $request->entity_uuid)->first()) {
                    self::subject($subject, $request->action, $request->revision === null ? null : (int) $request->revision);
                }
            }
            DB::table('sync_change_requests')->whereIn('id', $requests->pluck('id'))->update(['recorded_at' => now(), 'updated_at' => now()]);

            return $requests->count();
        });
    }

    private static function append(?string $farmUuid, string $type, string $uuid, string $action, ?int $revision, ?array $data): void
    {
        if (! $farmUuid) {
            return;
        }
        SyncChange::create([
            'farm_uuid' => $farmUuid,
            'entity_type' => $type,
            'entity_uuid' => $uuid,
            'action' => $action,
            'revision' => $revision ?? 1,
            'data_json' => $data,
        ]);
    }
}
