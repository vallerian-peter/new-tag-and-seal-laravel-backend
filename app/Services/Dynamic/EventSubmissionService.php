<?php

namespace App\Services\Dynamic;

use App\Enums\DefinitionStatus;
use App\Enums\EventBehavior;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSubject;
use App\Models\EventDefinition;
use App\Models\EventRecord;
use App\Models\FormDefinition;
use App\Models\User;
use App\Services\Ledger\LegacyBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventSubmissionService
{
    public function __construct(
        private readonly FormSchemaValidator $forms,
        private readonly RuleEvaluator $rules,
        private readonly EventBehaviorDispatcher $behaviors,
        private readonly BehaviorCatalog $catalog,
        private readonly LegacyBridge $bridge,
    ) {}

    public function submit(AnimalSubject $subject, array $input, User $actor): EventRecord
    {
        if ($subject->trashed()) {
            throw ValidationException::withMessages(['subject' => 'RECORD_DELETED']);
        }
        $existing = $this->existingSubmission($subject, $input, $actor);
        if ($existing) {
            return $existing;
        }
        if ($bridged = $this->adoptBridged($subject, $input)) {
            return $bridged;
        }
        if ((int) ($input['baseRevision'] ?? 0) !== (int) $subject->revision) {
            throw ValidationException::withMessages(['baseRevision' => 'RECORD_REVISION_CONFLICT']);
        }

        $definition = EventDefinition::query()
            ->where('uuid', $input['eventDefinitionUuid'] ?? null)
            ->where('status', DefinitionStatus::PUBLISHED->value)
            ->firstOrFail();
        $registration = AnimalBlueprint::where('uuid', $subject->blueprint_uuid)->where('version', $subject->blueprint_version)->firstOrFail();
        $workflow = AnimalBlueprint::where('uuid', $definition->blueprint_uuid)->where('version', $definition->blueprint_version)->firstOrFail();
        $sameVersion = $workflow->uuid === $registration->uuid && (int) $workflow->version === (int) $registration->version;
        $sameLineage = $workflow->code === $registration->code && $workflow->species_uuid === $registration->species_uuid
            && (int) $workflow->version > (int) $registration->version && $workflow->status === DefinitionStatus::PUBLISHED;
        if (! $sameVersion && ! $sameLineage) {
            throw ValidationException::withMessages(['event' => 'EVENT_NOT_ELIGIBLE']);
        }
        $settings = $workflow->settings_json ?? [];
        if ((isset($settings['subjectModes']) && ! in_array($subject->unit_type->value, $settings['subjectModes'], true))
            || (isset($settings['allowedSexCodes']) && ! in_array($subject->sex_code?->value, $settings['allowedSexCodes'], true))) {
            throw ValidationException::withMessages(['event' => 'EVENT_NOT_ELIGIBLE']);
        }
        $form = FormDefinition::query()->where('uuid', $definition->form_uuid)->where('version', $definition->form_version)->firstOrFail();
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $this->forms->assertPayload($form->schema_json, $payload);

        $facts = [
            'subject' => [
                'unit_type' => $subject->unit_type->value,
                'sex_code' => $subject->sex_code?->value,
                'stage_code' => $subject->current_stage_code,
                'lifecycle_status' => $subject->lifecycle_status,
            ],
            'user' => ['role' => $actor->role],
        ];
        if (! $this->rules->evaluate($definition->eligibility_rule_json, $facts)) {
            throw ValidationException::withMessages(['event' => 'EVENT_NOT_ELIGIBLE']);
        }
        $behavior = EventBehavior::tryFrom($definition->behavior_code);
        $contract = $this->catalog->find($definition->behavior_code);
        if (! $behavior || ! $contract) {
            throw ValidationException::withMessages(['behavior' => 'EVENT_BEHAVIOR_UNSUPPORTED']);
        }
        $roles = $this->catalog->resolve($contract, $form->schema_json, $payload);

        $record = DB::transaction(function () use ($subject, $input, $actor, $definition, $payload, $behavior, $roles): EventRecord {
            $locked = AnimalSubject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            // A retry may have waited for the first submission's transaction.
            if ($existing = $this->existingSubmission($locked, $input, $actor)) {
                return $existing;
            }
            if ((int) $locked->revision !== (int) $input['baseRevision']) {
                throw ValidationException::withMessages(['baseRevision' => 'RECORD_REVISION_CONFLICT']);
            }

            $record = EventRecord::create([
                'uuid' => $input['uuid'] ?? null,
                'farm_uuid' => $locked->farm_uuid,
                'subject_uuid' => $locked->uuid,
                'event_definition_uuid' => $definition->uuid,
                'event_code' => $definition->code,
                'behavior_code' => $definition->behavior_code,
                'event_definition_version' => $definition->blueprint_version,
                'form_uuid' => $definition->form_uuid,
                'form_version' => $definition->form_version,
                'occurred_at' => isset($input['occurredAt'])
                    ? Carbon::parse($input['occurredAt'])->setTimezone(config('app.timezone'))->startOfSecond()
                    : now(),
                'payload_json' => $payload,
                'record_status' => 'submitted',
                'created_by_uuid' => $actor->uuid,
                'source_device_uuid' => $input['sourceDeviceUuid'] ?? null,
                'idempotency_uuid' => $input['idempotencyUuid'],
                'revision' => 1,
            ]);

            $this->behaviors->apply($behavior, $locked, $roles, $actor, $record);
            $locked->revision = ((int) $locked->revision) + 1;
            $locked->updated_by_uuid = $actor->uuid;
            $locked->save();

            DB::table('audit_logs')->insert([
                'uuid' => (string) Str::uuid(),
                'actor_uuid' => $actor->uuid,
                'action' => 'dynamic_event.submitted',
                'entity_type' => 'event_record',
                'entity_uuid' => $record->uuid,
                'before_json' => null,
                'after_json' => json_encode($record->attributesToArray(), JSON_THROW_ON_ERROR),
                'reason' => null,
                'request_uuid' => $input['idempotencyUuid'],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $record;
        });
        $this->bridge->safely('ledger_to_legacy', (string) $record->event_code, $record->uuid, fn () => $this->bridge->toLegacy($record));

        return $record;
    }

    /**
     * A legacy screen saves its row and its configured extras under the same uuid. When
     * the legacy row reached the server first, the bridge already created the event, so
     * the submission only adds the extras.
     */
    private function adoptBridged(AnimalSubject $subject, array $input): ?EventRecord
    {
        if (empty($input['uuid'])) {
            return null;
        }
        $record = EventRecord::query()->where('uuid', $input['uuid'])->first();
        if (! $record) {
            return null;
        }
        $bridged = DB::table('legacy_record_mappings')->where('target_type', 'event_record')->where('target_uuid', $record->uuid)->exists();
        if (! $bridged || $record->subject_uuid !== $subject->uuid) {
            throw ValidationException::withMessages(['uuid' => 'RECORD_UUID_TAKEN']);
        }
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $form = FormDefinition::query()->where('uuid', $record->form_uuid)->where('version', $record->form_version)->first();
        $known = $form ? array_column(collect($form->schema_json['sections'] ?? [])->flatMap(fn ($section) => $section['fields'] ?? [])->all(), 'key') : [];
        $extras = array_diff_key(array_intersect_key($payload, array_flip($known)), $record->payload_json ?? []);
        if ($extras !== []) {
            $record->payload_json = ($record->payload_json ?? []) + $extras;
            $record->revision = ((int) $record->revision) + 1;
            $record->save();
        }

        return $record;
    }

    private function existingSubmission(AnimalSubject $subject, array $input, User $actor): ?EventRecord
    {
        if (empty($input['idempotencyUuid'])) {
            return null;
        }
        $existing = EventRecord::where('idempotency_uuid', $input['idempotencyUuid'])->first();
        if (! $existing) {
            return null;
        }
        if ($existing->subject_uuid !== $subject->uuid
            || $existing->created_by_uuid !== $actor->uuid
            || $existing->event_definition_uuid !== ($input['eventDefinitionUuid'] ?? null)
            || $this->payloadFingerprint($existing->payload_json) !== $this->payloadFingerprint($input['payload'] ?? [])
            || (isset($input['uuid']) && $existing->uuid !== $input['uuid'])
            || (isset($input['occurredAt']) && ! $existing->occurred_at->copy()->startOfSecond()->equalTo(Carbon::parse($input['occurredAt'])->startOfSecond()))
            || ($existing->source_device_uuid !== ($input['sourceDeviceUuid'] ?? null))) {
            throw ValidationException::withMessages(['idempotencyUuid' => 'IDEMPOTENCY_KEY_REUSED']);
        }

        return $existing;
    }

    private function payloadFingerprint(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        // JSON persistence may normalize 45.0 to 45. Object key order is not
        // significant, but list order and scalar types remain significant.
        return json_encode($normalize($payload), JSON_THROW_ON_ERROR);
    }
}
