<?php

namespace App\Services\Dynamic;

use App\Enums\EventBehavior;
use App\Models\AnimalSubject;
use App\Models\EventRecord;
use App\Models\User;
use App\Services\Ledger\LegacyBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Voids an event: the record is kept (never deleted), its subject effects are undone
 * where the behaviour allows it, and summaries are recomputed from what remains.
 */
class EventVoidService
{
    public function __construct(
        private readonly BehaviorCatalog $catalog,
        private readonly EventBehaviorDispatcher $behaviors,
        private readonly SubjectProjector $projector,
        private readonly LegacyBridge $bridge,
    ) {}

    public function void(EventRecord $record, User $actor, string $reason): EventRecord
    {
        $record = $this->voidLedger($record, $actor, $reason);
        $this->bridge->safely('ledger_void_to_legacy', (string) $record->event_code, $record->uuid, fn () => $this->bridge->voided($record));

        return $record;
    }

    private function voidLedger(EventRecord $record, User $actor, string $reason): EventRecord
    {
        return DB::transaction(function () use ($record, $actor, $reason): EventRecord {
            $record = EventRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->record_status === 'voided') {
                return $record;
            }
            $contract = $this->catalog->find($record->behavior_code);
            if ($contract && ! $contract->reversible) {
                throw ValidationException::withMessages(['event' => 'EVENT_NOT_REVERSIBLE']);
            }
            $subject = AnimalSubject::withTrashed()->where('uuid', $record->subject_uuid)->lockForUpdate()->first();
            $behavior = EventBehavior::tryFrom((string) $record->behavior_code);
            if ($subject && $behavior) {
                $this->behaviors->reverse($behavior, $subject, $this->projector->roles($record), $record);
            }
            $record->forceFill([
                'record_status' => 'voided',
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by_uuid' => $actor->uuid,
                'revision' => ((int) $record->revision) + 1,
            ])->save();
            if ($subject) {
                $this->projector->rebuildSubject($subject->uuid);
                if ($subject->isDirty()) {
                    $subject->revision = ((int) $subject->revision) + 1;
                    $subject->updated_by_uuid = $actor->uuid;
                    $subject->save();
                }
            }
            DB::table('audit_logs')->insert([
                'uuid' => (string) Str::uuid(),
                'actor_uuid' => $actor->uuid,
                'action' => 'dynamic_event.voided',
                'entity_type' => 'event_record',
                'entity_uuid' => $record->uuid,
                'before_json' => null,
                'after_json' => json_encode($record->attributesToArray(), JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'request_uuid' => (string) Str::uuid(),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $record;
        });
    }
}
