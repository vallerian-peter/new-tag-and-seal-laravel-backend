<?php

namespace App\Services\Ledger;

use App\Enums\DefinitionStatus;
use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Models\EventDefinition;
use App\Models\EventRecord;
use App\Models\FormDefinition;
use App\Models\Livestock;
use App\Services\Dynamic\SubjectProjector;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Keeps each legacy log row and its ledger event in step, in both directions.
 *
 * Legacy → ledger runs on every legacy save, whatever the family authority, so the
 * ledger is complete before a family switches. Ledger → legacy runs only for families
 * on `ledger`; before that the legacy screens still write their own rows.
 */
class LegacyBridge
{
    public const ANOMALY_RUN = 'ledger-bridge';

    public function __construct(
        private readonly LegacyLookups $lookups,
        private readonly LegacySubjectMirror $subjects,
        private readonly SubjectProjector $projector,
        private readonly DynamicV1Installer $installer,
        private readonly FinanceLinker $finance,
    ) {}

    public static function authority(string $family): string
    {
        $value = DB::table('platform_feature_flags')->where('key', "family.$family.authority")->value('value_json');

        return json_decode((string) $value, true)['authority'] ?? 'legacy';
    }

    /** Runs a bridge step without letting its failure break the caller's write. */
    public function safely(string $step, string $table, mixed $key, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $error) {
            report($error);
            $this->anomaly($step, 'BRIDGE_FAILED', $table, $key, ['message' => $error->getMessage()]);

            return null;
        }
    }

    /** @param  array<string, mixed>  $row */
    public function fromLegacy(string $table, array $row): ?EventRecord
    {
        $spec = LegacyCatalog::table($table);
        if (! $spec || empty($row['livestockUuid'])) {
            return null;
        }

        return DB::transaction(function () use ($table, $spec, $row): ?EventRecord {
            $subject = AnimalSubject::withTrashed()->where('uuid', $row['livestockUuid'])->first() ?? $this->subjects->mirrorUuid($row['livestockUuid']);
            if (! $subject) {
                $this->anomaly('legacy_to_ledger', 'EVENT_SUBJECT_MISSING', $table, $row['id'], ['livestockUuid' => $row['livestockUuid']]);

                return null;
            }
            $speciesCode = AnimalSpecies::query()->where('uuid', $subject->species_uuid)->value('code');
            $event = LegacyCatalog::eventFor($spec, (string) $speciesCode, $row);
            $definition = $event ? $this->definition($subject, $event['code']) : null;
            if (! $definition) {
                $this->anomaly('legacy_to_ledger', 'EVENT_DEFINITION_MISSING', $table, $row['id'], ['species' => $speciesCode, 'event' => $event['code'] ?? null]);

                return null;
            }

            $mapping = $this->mapping($table, $row['id']);
            $record = $mapping ? EventRecord::query()->where('uuid', $mapping->target_uuid)->first() : null;
            if (! $record && $this->isUuid($row['uuid'] ?? null)) {
                $candidate = EventRecord::query()->where('uuid', $row['uuid'])->first();
                if ($candidate && $candidate->subject_uuid === $subject->uuid && ! $this->isMapped($candidate->uuid)) {
                    $record = $candidate;
                }
            }
            $uuid = $record->uuid ?? $mapping->target_uuid ?? (
                $this->isUuid($row['uuid'] ?? null) && ! EventRecord::query()->where('uuid', $row['uuid'])->exists()
                    ? $row['uuid']
                    : $this->installer->uuid("legacy/event/$table/{$row['id']}")
            );

            $payload = $this->payloadFromRow($spec, (string) $speciesCode, $row);
            $voided = isset($spec['void']['status']) && ($row['status'] ?? null) === $spec['void']['status'];
            $values = [
                'farm_uuid' => $row['farmUuid'] ?? $subject->farm_uuid,
                'subject_uuid' => $subject->uuid,
                'event_definition_uuid' => $definition->uuid,
                'event_code' => $definition->code,
                'behavior_code' => $definition->behavior_code,
                'event_definition_version' => $definition->blueprint_version,
                'form_uuid' => $definition->form_uuid,
                'form_version' => $definition->form_version,
                'occurred_at' => $this->occurredAt($row),
            ];
            if ($record) {
                // Keep only configured extras of the new form; anything else (such as the raw
                // row stored by the first upgrade) is replaced by the converted values.
                $legacyKeys = array_column(array_filter($spec['fields'], fn ($field) => $field['column'] || isset($field['constant'])), 'key');
                $formKeys = collect(FormDefinition::query()->where('uuid', $definition->form_uuid)->where('version', $definition->form_version)->first()?->schema_json['sections'] ?? [])
                    ->flatMap(fn ($section) => array_column($section['fields'] ?? [], 'key'))->all();
                $kept = array_diff_key(array_intersect_key($record->payload_json ?? [], array_flip($formKeys)), array_flip($legacyKeys));
                $record->fill($values + ['payload_json' => $payload + $kept]);
                if ($voided && $record->record_status !== 'voided') {
                    $record->fill(['record_status' => 'voided', 'void_reason' => 'Voided in the legacy app', 'voided_at' => now()]);
                }
                if ($record->isDirty()) {
                    $record->revision = ((int) $record->revision) + 1;
                    $record->save();
                }
            } else {
                $record = EventRecord::query()->create($values + [
                    'uuid' => $uuid,
                    'payload_json' => $payload,
                    'record_status' => $voided ? 'voided' : 'confirmed',
                    'void_reason' => $voided ? 'Voided in the legacy app' : null,
                    'voided_at' => $voided ? now() : null,
                    'idempotency_uuid' => $this->installer->uuid("legacy/event-idempotency/$table/{$row['id']}"),
                    'revision' => 1,
                    'created_at' => $row['created_at'] ?? now(),
                ]);
            }
            $this->map($table, $row['id'], $row['uuid'] ?? null, $record->uuid);
            $this->refreshSubjectState($subject);

            return $record;
        });
    }

    /** @param  array<string, mixed>  $row */
    public function legacyDeleted(string $table, array $row): void
    {
        $mapping = $this->mapping($table, $row['id'] ?? null);
        $record = $mapping ? EventRecord::query()->where('uuid', $mapping->target_uuid)->first() : null;
        if (! $record || $record->record_status === 'voided') {
            return;
        }
        DB::transaction(function () use ($record): void {
            $record->forceFill([
                'record_status' => 'voided', 'void_reason' => 'Deleted in the legacy app', 'voided_at' => now(),
                'revision' => ((int) $record->revision) + 1,
            ])->save();
            $subject = AnimalSubject::withTrashed()->where('uuid', $record->subject_uuid)->first();
            if ($subject) {
                $this->refreshSubjectState($subject);
            }
        });
    }

    /** Writes the legacy copy of an event submitted to the ledger. */
    public function toLegacy(EventRecord $record): void
    {
        [$table, $spec, $event] = LegacyCatalog::forEventCode((string) $record->event_code) ?? [null, null, null];
        if (! $table || self::authority($spec['family']) !== 'ledger' || ! Schema::hasTable($table)) {
            return;
        }
        $livestock = Livestock::query()->where('uuid', $record->subject_uuid)->first();
        if (! $livestock) {
            return;
        }

        DB::transaction(function () use ($record, $table, $spec, $event, $livestock): void {
            $subject = AnimalSubject::withTrashed()->where('uuid', $record->subject_uuid)->firstOrFail();
            $speciesCode = (string) AnimalSpecies::query()->where('uuid', $subject->species_uuid)->value('code');
            $values = $this->rowFromPayload($spec, $event, $speciesCode, $record, $livestock);
            $mapping = DB::table('legacy_record_mappings')->where('source_table', $table)->where('target_type', 'event_record')->where('target_uuid', $record->uuid)->first();
            if ($mapping) {
                DB::table($table)->where('id', $mapping->source_key)->update($values + ['updated_at' => now()]);
                $id = $mapping->source_key;
            } else {
                $id = DB::table($table)->insertGetId($values + ['uuid' => $record->uuid, 'created_at' => now(), 'updated_at' => now()]);
                $this->map($table, $id, $record->uuid, $record->uuid);
            }

            $changes = [];
            foreach ($spec['livestock'] ?? [] as $column => $source) {
                $changes[$column] = $source['value'] ?? $values[$source['column']] ?? null;
            }
            if ($spec['offspring'] ?? false) {
                $this->legacyOffspring($record, $livestock);
            }
            if (array_filter($changes, fn ($value) => $value !== null) !== []) {
                $livestock->fill(array_filter($changes, fn ($value) => $value !== null))->save();
            }
        });
    }

    /** Mirrors a ledger void onto the legacy row. */
    public function voided(EventRecord $record): void
    {
        [$table, $spec] = LegacyCatalog::forEventCode((string) $record->event_code) ?? [null, null];
        $mapping = $table ? DB::table('legacy_record_mappings')->where('source_table', $table)->where('target_type', 'event_record')->where('target_uuid', $record->uuid)->first() : null;
        if (! $mapping) {
            return;
        }
        isset($spec['void']['status'])
            ? DB::table($table)->where('id', $mapping->source_key)->update(['status' => $spec['void']['status'], 'updated_at' => now()])
            : DB::table($table)->where('id', $mapping->source_key)->delete();
    }

    /** @return array<string, mixed> configured payload of a legacy row */
    public function payloadFromRow(array $spec, string $speciesCode, array $row): array
    {
        $payload = [];
        foreach ($spec['fields'] as $field) {
            if (isset($field['constant'])) {
                $payload[$field['key']] = $field['constant'];

                continue;
            }
            $value = $this->toLedgerValue($field, $speciesCode, $row[$field['column']] ?? null);
            if ($value !== null) {
                $payload[$field['key']] = $value;
            }
        }

        return $payload;
    }

    /** The payload in a form where equal values compare equal (date-times as UTC instants). */
    public function comparable(array $spec, array $payload): array
    {
        foreach ($spec['fields'] as $field) {
            if ($field['component'] === 'date_time' && isset($payload[$field['key']])) {
                $payload[$field['key']] = $this->date($payload[$field['key']])?->utc()->format('Y-m-d\TH:i:s\Z') ?? $payload[$field['key']];
            }
        }

        return $payload;
    }

    /** Recomputes current-state attributes and summaries from the subject's events. */
    public function refreshSubjectState(AnimalSubject $subject): void
    {
        $latest = fn (string $behavior) => EventRecord::query()->where('subject_uuid', $subject->uuid)->where('behavior_code', $behavior)
            ->where('record_status', '!=', 'voided')->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $attributes = $subject->attributes_json ?? [];
        if (($weight = $latest('update_weight')) && is_numeric($this->projector->roles($weight)['weight'] ?? null)) {
            $attributes['current_weight'] = $this->projector->roles($weight)['weight'];
        }
        if ($treatment = $latest('record_treatment')) {
            $attributes['last_treated_at'] = $treatment->occurred_at->toIso8601String();
        }
        if (($stage = $latest('transition_stage')) && is_string($this->projector->roles($stage)['stage_code'] ?? null)) {
            $subject->current_stage_code = $this->projector->roles($stage)['stage_code'];
        }
        $subject->attributes_json = $attributes;
        $this->projector->rebuildSubject($subject->uuid);
        if ($subject->isDirty()) {
            $subject->revision = ((int) $subject->revision) + 1;
            $subject->save();
        }
    }

    public function anomaly(string $step, string $code, ?string $table, mixed $key, array $details): void
    {
        DB::table('migration_anomalies')->insert([
            'run_uuid' => $this->installer->uuid(self::ANOMALY_RUN), 'step_code' => $step, 'severity' => 'warning',
            'error_code' => $code, 'source_table' => $table, 'source_key' => $key === null ? null : (string) $key,
            'details_json' => json_encode($details, JSON_THROW_ON_ERROR), 'resolution_status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function toLedgerValue(array $field, string $speciesCode, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field['component']) {
            'integer' => is_numeric($value) ? (int) round((float) $value) : null,
            'decimal', 'measurement' => is_numeric($value) ? $value + 0 : (is_numeric(preg_replace('/[^0-9.]/', '', (string) $value)) && preg_replace('/[^0-9.]/', '', (string) $value) !== '' ? preg_replace('/[^0-9.]/', '', (string) $value) + 0 : null),
            'date' => $this->date($value)?->toDateString(),
            'date_time' => $this->date($value)?->toIso8601String(),
            'single_select' => isset($field['lookup'])
                ? $this->lookups->code($field['lookup'], $speciesCode, $value)
                : (in_array($value, array_column($field['options'] ?? [], 'value'), true) ? $value : null),
            'multi_select' => ($codes = array_values(array_filter(array_map(
                fn ($id) => $this->lookups->code($field['lookup'], $speciesCode, $id),
                is_array($decoded = is_string($value) ? json_decode($value, true) : $value) ? $decoded : [],
            )))) === [] ? null : $codes,
            default => trim((string) $value) === '' ? null : trim((string) $value),
        };
    }

    private function toLegacyValue(array $field, string $speciesCode, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field['component']) {
            'date_time' => $this->date($value)?->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'single_select' => isset($field['lookup']) ? $this->lookups->legacyId($field['lookup'], $speciesCode, $value) : $value,
            'multi_select' => json_encode(array_values(array_filter(array_map(fn ($code) => $this->lookups->legacyId($field['lookup'], $speciesCode, $code), (array) $value)))),
            default => $value,
        };
    }

    /** @return array<string, mixed> legacy columns of a ledger event */
    private function rowFromPayload(array $spec, array $event, string $speciesCode, EventRecord $record, Livestock $livestock): array
    {
        $payload = $record->payload_json ?? [];
        $values = [
            'farmUuid' => $record->farm_uuid,
            'livestockUuid' => $record->subject_uuid,
            'eventDate' => $record->occurred_at->format('Y-m-d H:i:s'),
        ] + $event['match'];
        foreach ($spec['fields'] as $field) {
            if ($field['column']) {
                $val = $this->toLegacyValue($field, $speciesCode, $payload[$field['key']] ?? null);
                if ($val !== null || ! array_key_exists($field['column'], $values)) {
                    $values[$field['column']] = $val;
                }
            }
        }
        foreach ($spec['derived'] ?? [] as $column => $rule) {
            if (isset($rule['sum'])) {
                $values[$column] = array_sum(array_map(fn ($source) => (int) ($values[$source] ?? 0), $rule['sum']));
            } elseif (($values[$column] ?? null) === null) {
                $values[$column] = match (true) {
                    isset($rule['default_event_date']) => $record->occurred_at->toDateString(),
                    isset($rule['previous_weight']) => $this->previousWeight($record, $livestock),
                    default => $rule['default'] ?? null,
                };
            }
        }
        if (isset($spec['void']['status'])) {
            $values['status'] = $record->record_status === 'voided' ? $spec['void']['status'] : $this->activeStatus($spec);
        }

        return $values;
    }

    private function legacyOffspring(EventRecord $record, Livestock $mother): void
    {
        $offspring = AnimalSubject::query()->where('attributes_json->birth_event_uuid', $record->uuid)->get();
        foreach ($offspring as $index => $child) {
            if (Livestock::withTrashed()->where('uuid', $child->uuid)->exists()) {
                continue;
            }
            Livestock::query()->create([
                'farmUuid' => $mother->farmUuid,
                'uuid' => $child->uuid,
                'livestockTypeId' => $mother->livestockTypeId,
                'name' => $child->display_name ?: $mother->name.' #'.($index + 1),
                'dateOfBirth' => $record->occurred_at->toDateString(),
                'motherUuid' => $mother->uuid,
                'birthEventUuid' => $record->uuid,
                'isIdentified' => false,
                'gender' => $child->sex_code?->value === 'male' ? 'male' : 'female',
                'breedId' => $mother->breedId,
                'speciesId' => $mother->speciesId,
                'status' => 'active',
                'weightAsOnRegistration' => $child->attributes_json['birth_weight'] ?? null,
                'revision' => 1,
            ]);
        }
    }

    private function previousWeight(EventRecord $record, Livestock $livestock): mixed
    {
        $previous = EventRecord::query()->where('subject_uuid', $record->subject_uuid)->where('behavior_code', 'update_weight')
            ->where('record_status', '!=', 'voided')->where('id', '!=', $record->id)
            ->where('occurred_at', '<=', $record->occurred_at)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $weight = $previous ? ($this->projector->roles($previous)['weight'] ?? null) : null;

        return $weight ?? $livestock->weightAsOnRegistration ?? 0;
    }

    private function activeStatus(array $spec): string
    {
        return match ($spec['void']['status']) {
            'failed' => 'completed',
            'cancelled' => 'completed',
            default => 'active',
        };
    }

    private function definition(AnimalSubject $subject, string $code): ?EventDefinition
    {
        return EventDefinition::query()->where('blueprint_uuid', $subject->blueprint_uuid)->where('blueprint_version', $subject->blueprint_version)
            ->where('code', $code)->where('status', DefinitionStatus::PUBLISHED->value)->first();
    }

    private function mapping(string $table, mixed $id): ?object
    {
        return $id === null ? null : DB::table('legacy_record_mappings')->where('source_table', $table)->where('source_key', (string) $id)->where('target_type', 'event_record')->first();
    }

    private function map(string $table, mixed $id, ?string $sourceUuid, string $targetUuid): void
    {
        DB::table('legacy_record_mappings')->updateOrInsert([
            'source_table' => $table, 'source_key' => (string) $id, 'target_type' => 'event_record',
        ], ['source_uuid' => $sourceUuid, 'target_uuid' => $targetUuid, 'target_version' => 1, 'updated_at' => now(), 'created_at' => now()]);
        foreach (array_unique(array_filter([$sourceUuid, $targetUuid])) as $logUuid) {
            $this->finance->linkLog($logUuid, $targetUuid);
        }
    }

    private function isMapped(string $uuid): bool
    {
        return DB::table('legacy_record_mappings')->where('target_type', 'event_record')->where('target_uuid', $uuid)->exists();
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function occurredAt(array $row): Carbon
    {
        return $this->date($row['eventDate'] ?? null) ?? $this->date($row['created_at'] ?? null) ?? now();
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
