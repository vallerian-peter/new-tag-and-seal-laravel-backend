<?php

namespace App\Services\Dynamic;

use App\Enums\EventBehavior;
use App\Models\AnimalSubject;
use App\Models\EventRecord;
use App\Models\FormDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Current-state summaries derived from the event ledger. Every write happens inside
 * the caller's transaction; everything can be recomputed from submitted events.
 */
class SubjectProjector
{
    private const PROJECTED = ['record_birth', 'record_egg_or_clutch', 'record_production_yield'];

    public function __construct(private readonly BehaviorCatalog $catalog) {}

    public function addBirth(EventRecord $record, AnimalSubject $subject, int $alive, int $dead): void
    {
        $row = DB::table('subject_reproduction_state')->where('subject_uuid', $subject->uuid)->lockForUpdate()->first();
        $occurred = $record->occurred_at;
        $isLatest = ! $row || ! $row->last_birth_at || $occurred->greaterThanOrEqualTo($row->last_birth_at);
        $values = [
            'farm_uuid' => $subject->farm_uuid,
            'births_count' => ($row->births_count ?? 0) + 1,
            'total_born_alive' => ($row->total_born_alive ?? 0) + $alive,
            'total_born_dead' => ($row->total_born_dead ?? 0) + $dead,
            'updated_at' => now(),
        ];
        if ($isLatest) {
            $values += ['last_birth_at' => $occurred, 'last_born_alive' => $alive, 'last_born_dead' => $dead, 'last_event_uuid' => $record->uuid];
        }
        $row
            ? DB::table('subject_reproduction_state')->where('id', $row->id)->update($values)
            : DB::table('subject_reproduction_state')->insert($values + ['subject_uuid' => $subject->uuid, 'created_at' => now()]);
    }

    public function addProduction(EventRecord $record, AnimalSubject $subject, string $product, int|float $quantity, int|float $damaged, ?string $unit): bool
    {
        $key = ['subject_uuid' => $subject->uuid, 'production_date' => $record->occurred_at->toDateString(), 'product_code' => $product];
        $row = DB::table('subject_production_daily')->where($key)->lockForUpdate()->first();
        $values = [
            'farm_uuid' => $subject->farm_uuid,
            'quantity' => ($row->quantity ?? 0) + $quantity,
            'damaged_quantity' => ($row->damaged_quantity ?? 0) + $damaged,
            'unit' => $unit ?? $row->unit ?? null,
            'event_count' => ($row->event_count ?? 0) + 1,
            'updated_at' => now(),
        ];
        $row
            ? DB::table('subject_production_daily')->where('id', $row->id)->update($values)
            : DB::table('subject_production_daily')->insert($key + $values + ['created_at' => now()]);

        return true;
    }

    /** Recomputes this subject's summary rows from its submitted events. */
    public function rebuildSubject(string $subjectUuid): void
    {
        [$reproduction, $production] = $this->compute($subjectUuid);
        DB::table('subject_reproduction_state')->where('subject_uuid', $subjectUuid)->delete();
        DB::table('subject_production_daily')->where('subject_uuid', $subjectUuid)->delete();
        if ($reproduction) {
            DB::table('subject_reproduction_state')->insert($reproduction + ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($production as $row) {
            DB::table('subject_production_daily')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return array<int, string> human-readable differences between stored and recomputed rows */
    public function differences(string $subjectUuid): array
    {
        [$reproduction, $production] = $this->compute($subjectUuid);
        $differences = [];
        $stored = DB::table('subject_reproduction_state')->where('subject_uuid', $subjectUuid)->first();
        foreach (['births_count', 'total_born_alive', 'total_born_dead', 'last_born_alive', 'last_born_dead'] as $column) {
            if ((string) ($stored->{$column} ?? '') !== (string) ($reproduction[$column] ?? '')) {
                $differences[] = "$subjectUuid reproduction.$column stored=".($stored->{$column} ?? 'null').' expected='.($reproduction[$column] ?? 'null');
            }
        }
        $storedProduction = DB::table('subject_production_daily')->where('subject_uuid', $subjectUuid)->get()
            ->keyBy(fn ($row) => substr((string) $row->production_date, 0, 10).'|'.$row->product_code);
        foreach ($production as $row) {
            $key = $row['production_date'].'|'.$row['product_code'];
            $current = $storedProduction->pull($key);
            if (! $current || (float) $current->quantity !== (float) $row['quantity'] || (float) $current->damaged_quantity !== (float) $row['damaged_quantity'] || (int) $current->event_count !== $row['event_count']) {
                $differences[] = "$subjectUuid production[$key] does not match its events";
            }
        }
        foreach ($storedProduction->keys() as $key) {
            $differences[] = "$subjectUuid production[$key] has no supporting events";
        }

        return $differences;
    }

    /** Restores attribute stamps after a weight or treatment event is voided. */
    public function rebuildSubjectAttributes(AnimalSubject $subject, EventRecord $voided): void
    {
        $attributes = $subject->attributes_json;
        $latest = fn (string $behavior) => EventRecord::query()
            ->where('subject_uuid', $subject->uuid)->where('behavior_code', $behavior)
            ->where('record_status', '!=', 'voided')->where('id', '!=', $voided->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->first();
        if ($voided->behavior_code === EventBehavior::UPDATE_WEIGHT->value) {
            $previous = $latest(EventBehavior::UPDATE_WEIGHT->value);
            $roles = $previous ? $this->roles($previous) : [];
            if (isset($roles['weight'])) {
                $attributes['current_weight'] = $roles['weight'];
            } else {
                unset($attributes['current_weight']);
            }
        }
        if ($voided->behavior_code === EventBehavior::RECORD_TREATMENT->value) {
            $previous = $latest(EventBehavior::RECORD_TREATMENT->value);
            if ($previous) {
                $attributes['last_treated_at'] = $previous->occurred_at->toIso8601String();
            } else {
                unset($attributes['last_treated_at']);
            }
        }
        $subject->attributes_json = $attributes;
    }

    /** @return array<string, mixed> role values of a stored event */
    public function roles(EventRecord $record): array
    {
        $behavior = $this->catalog->find($record->behavior_code);
        $form = FormDefinition::query()->where('uuid', $record->form_uuid)->where('version', $record->form_version)->first();

        return $behavior && $form ? $this->catalog->resolve($behavior, $form->schema_json, $record->payload_json ?? []) : [];
    }

    private function compute(string $subjectUuid): array
    {
        $farm = AnimalSubject::withTrashed()->where('uuid', $subjectUuid)->value('farm_uuid');
        $reproduction = null;
        $production = [];
        $events = EventRecord::query()->where('subject_uuid', $subjectUuid)->where('record_status', '!=', 'voided')
            ->whereIn('behavior_code', self::PROJECTED)->orderBy('occurred_at')->orderBy('id')->get();
        foreach ($events as $event) {
            $roles = $this->roles($event);
            if ($event->behavior_code === 'record_birth') {
                $alive = (int) ($roles['born_alive'] ?? 0);
                $dead = (int) ($roles['born_dead'] ?? 0);
                $reproduction = [
                    'subject_uuid' => $subjectUuid,
                    'farm_uuid' => $farm,
                    'births_count' => ($reproduction['births_count'] ?? 0) + 1,
                    'total_born_alive' => ($reproduction['total_born_alive'] ?? 0) + $alive,
                    'total_born_dead' => ($reproduction['total_born_dead'] ?? 0) + $dead,
                    'last_birth_at' => $event->occurred_at,
                    'last_born_alive' => $alive,
                    'last_born_dead' => $dead,
                    'last_event_uuid' => $event->uuid,
                ];

                continue;
            }
            [$product, $quantity, $damaged, $unit] = $event->behavior_code === 'record_egg_or_clutch'
                ? ['eggs', (int) ($roles['egg_count'] ?? 0), (int) ($roles['damaged_count'] ?? 0), null]
                : [is_string($roles['product'] ?? null) && $roles['product'] !== '' ? $roles['product'] : 'yield', (float) ($roles['quantity'] ?? 0), 0, is_string($roles['unit'] ?? null) && $roles['unit'] !== '' ? $roles['unit'] : null];
            $key = $event->occurred_at->toDateString().'|'.$product;
            $production[$key] = [
                'subject_uuid' => $subjectUuid,
                'farm_uuid' => $farm,
                'production_date' => $event->occurred_at->toDateString(),
                'product_code' => $product,
                'quantity' => ($production[$key]['quantity'] ?? 0) + $quantity,
                'damaged_quantity' => ($production[$key]['damaged_quantity'] ?? 0) + $damaged,
                'unit' => $unit ?? $production[$key]['unit'] ?? null,
                'event_count' => ($production[$key]['event_count'] ?? 0) + 1,
            ];
        }

        return [$reproduction, array_values($production)];
    }
}
