<?php

namespace App\Services\Dynamic;

use App\Enums\EventBehavior;
use App\Models\AnimalSubject;
use App\Models\EventRecord;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies a behaviour's effects. Inputs arrive keyed by role (see BehaviorCatalog),
 * never by the Admin's field keys.
 */
class EventBehaviorDispatcher
{
    public function __construct(
        private readonly IdentifierAssignmentService $identifiers,
        private readonly SubjectProjector $projector,
    ) {}

    /** @param  array<string, mixed>  $roles */
    public function apply(EventBehavior $behavior, AnimalSubject $subject, array $roles, User $actor, ?EventRecord $record = null): void
    {
        match ($behavior) {
            EventBehavior::UPDATE_WEIGHT => $this->updateAttribute($subject, 'current_weight', $roles, 'weight'),
            EventBehavior::RECORD_TREATMENT => $this->stampAttribute($subject, 'last_treated_at', $record),
            EventBehavior::TRANSITION_STAGE => $this->updateColumn($subject, 'current_stage_code', $roles, 'stage_code'),
            EventBehavior::CHANGE_LIFECYCLE_STATUS => $this->updateColumn($subject, 'lifecycle_status', $roles, 'lifecycle_status'),
            EventBehavior::TRANSFER_SUBJECT => $this->updateColumn($subject, 'farm_uuid', $roles, 'destination_farm_uuid'),
            EventBehavior::ADJUST_GROUP_QUANTITY,
            EventBehavior::RECORD_GROUP_CENSUS => $this->updateQuantity($subject, $roles),
            EventBehavior::RECORD_BIOMASS_SAMPLE => $this->updateColumn($subject, 'biomass', $roles, 'biomass'),
            EventBehavior::RECORD_BIRTH => $this->recordBirth($subject, $roles, $actor, $record),
            EventBehavior::CREATE_OFFSPRING => $this->createOffspring($subject, $roles['offspring'] ?? null, $actor, true),
            EventBehavior::RECORD_HATCHING => $this->changeGroupQuantity($subject, $this->count($roles, 'hatched_count', false)),
            EventBehavior::RECORD_MORTALITY => $this->recordMortality($subject, $roles),
            EventBehavior::RECORD_EGG_OR_CLUTCH => $record && $this->projector->addProduction($record, $subject, 'eggs', $this->count($roles, 'egg_count', true), $this->count($roles, 'damaged_count', false), null),
            EventBehavior::RECORD_PRODUCTION_YIELD => $record && $this->recordYield($subject, $roles, $record),
            EventBehavior::SPLIT_GROUP => $this->splitGroup($subject, $roles, $actor),
            EventBehavior::MERGE_GROUPS => $this->mergeGroups($subject, $roles, $actor),
            EventBehavior::ASSIGN_IDENTIFIER => $this->assignIdentifier($subject, $roles, $actor),
            EventBehavior::RETIRE_IDENTIFIER => $this->retireIdentifier($subject, $roles, $actor),
            EventBehavior::RECORD_ONLY,
            EventBehavior::RECORD_SPAWNING,
            EventBehavior::RECORD_ENVIRONMENT_MEASUREMENT,
            EventBehavior::SCHEDULE_FOLLOWUP => null,
        };
    }

    /**
     * Undoes the subject-state effects of a reversible behaviour. Summary tables are
     * rebuilt from the remaining events by the caller.
     *
     * @param  array<string, mixed>  $roles
     */
    public function reverse(EventBehavior $behavior, AnimalSubject $subject, array $roles, EventRecord $record): void
    {
        match ($behavior) {
            EventBehavior::RECORD_MORTALITY => $subject->quantity = ((int) $subject->quantity) + $this->count($roles, 'count', true),
            EventBehavior::RECORD_HATCHING => $this->isGroup($subject) && $subject->quantity = max(0, ((int) $subject->quantity) - $this->count($roles, 'hatched_count', false)),
            EventBehavior::RECORD_BIRTH => $this->reverseBirth($subject, $roles, $record),
            EventBehavior::UPDATE_WEIGHT, EventBehavior::RECORD_TREATMENT => $this->projector->rebuildSubjectAttributes($subject, $record),
            default => null,
        };
    }

    private function updateAttribute(AnimalSubject $subject, string $attribute, array $roles, string $role): void
    {
        if (! isset($roles[$role]) || ! is_numeric($roles[$role])) {
            throw ValidationException::withMessages([$role => 'A numeric value is required.']);
        }
        $attributes = $subject->attributes_json;
        $attributes[$attribute] = $roles[$role];
        $subject->attributes_json = $attributes;
    }

    private function stampAttribute(AnimalSubject $subject, string $attribute, ?EventRecord $record): void
    {
        $attributes = $subject->attributes_json;
        $attributes[$attribute] = ($record?->occurred_at ?? now())->toIso8601String();
        $subject->attributes_json = $attributes;
    }

    private function updateColumn(AnimalSubject $subject, string $column, array $roles, string $role): void
    {
        if (! array_key_exists($role, $roles)) {
            throw ValidationException::withMessages([$role => 'This behavior requires the field.']);
        }
        $subject->{$column} = $roles[$role];
    }

    private function updateQuantity(AnimalSubject $subject, array $roles): void
    {
        $this->assertGroup($subject);
        $subject->quantity = $this->count($roles, 'quantity', true);
    }

    private function count(array $roles, string $role, bool $required): int
    {
        $value = $roles[$role] ?? null;
        if ($value === null && ! $required) {
            return 0;
        }
        if (! is_int($value) || $value < 0) {
            throw ValidationException::withMessages([$role => 'A non-negative whole number is required.']);
        }

        return $value;
    }

    private function recordBirth(AnimalSubject $subject, array $roles, User $actor, ?EventRecord $record): void
    {
        $alive = $this->count($roles, 'born_alive', true);
        $dead = $this->count($roles, 'born_dead', false);
        $offspring = $roles['offspring'] ?? [];
        if ($offspring !== [] && $offspring !== null) {
            if ($this->isGroup($subject)) {
                throw ValidationException::withMessages(['offspring' => 'Groups record births as a count, not individual offspring.']);
            }
            if (! is_array($offspring) || count($offspring) > $alive) {
                throw ValidationException::withMessages(['offspring' => 'There cannot be more offspring than animals born alive.']);
            }
            $this->createOffspring($subject, $offspring, $actor, false, $record);
        }
        if ($this->isGroup($subject)) {
            $this->changeGroupQuantity($subject, $alive);
        }
        if ($record) {
            $this->projector->addBirth($record, $subject, $alive, $dead);
        }
    }

    private function reverseBirth(AnimalSubject $subject, array $roles, EventRecord $record): void
    {
        if (! empty($roles['offspring'])) {
            throw ValidationException::withMessages(['event' => 'EVENT_NOT_REVERSIBLE']);
        }
        if ($this->isGroup($subject)) {
            $subject->quantity = max(0, ((int) $subject->quantity) - $this->count($roles, 'born_alive', true));
        }
    }

    private function recordMortality(AnimalSubject $subject, array $roles): void
    {
        $this->assertGroup($subject);
        $count = $this->count($roles, 'count', true);
        if ($count > (int) $subject->quantity) {
            throw ValidationException::withMessages(['count' => 'More animals cannot be lost than the group holds.']);
        }
        $subject->quantity = ((int) $subject->quantity) - $count;
    }

    private function recordYield(AnimalSubject $subject, array $roles, EventRecord $record): void
    {
        $quantity = $roles['quantity'] ?? null;
        if (! is_int($quantity) && ! is_float($quantity) || $quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'A non-negative quantity is required.']);
        }
        $product = is_string($roles['product'] ?? null) && $roles['product'] !== '' ? $roles['product'] : 'yield';
        $unit = is_string($roles['unit'] ?? null) && $roles['unit'] !== '' ? $roles['unit'] : null;
        $this->projector->addProduction($record, $subject, $product, $quantity, 0, $unit);
    }

    private function changeGroupQuantity(AnimalSubject $subject, int $delta): void
    {
        if ($this->isGroup($subject)) {
            $subject->quantity = ((int) $subject->quantity) + $delta;
        }
    }

    private function createOffspring(AnimalSubject $subject, mixed $offspring, User $actor, bool $required, ?EventRecord $record = null): void
    {
        if ($required && (! is_array($offspring) || $offspring === [])) {
            throw ValidationException::withMessages(['offspring' => 'At least one offspring record is required.']);
        }

        foreach ($offspring ?? [] as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['offspring' => 'Every offspring entry must be an object.']);
            }
            AnimalSubject::create([
                'uuid' => $item['uuid'] ?? (string) Str::uuid(),
                'farm_uuid' => $subject->farm_uuid,
                'species_uuid' => $subject->species_uuid,
                'blueprint_uuid' => $subject->blueprint_uuid,
                'blueprint_version' => $subject->blueprint_version,
                'unit_type' => 'individual',
                'display_name' => $item['display_name'] ?? null,
                'birth_or_start_date' => $item['birth_date'] ?? $record?->occurred_at?->toDateString() ?? now()->toDateString(),
                'sex_code' => $item['sex_code'] ?? 'unknown',
                'lifecycle_status' => 'active',
                'attributes_json' => array_filter(array_merge($item['attributes'] ?? [], [
                    'mother_uuid' => $subject->uuid,
                    'birth_event_uuid' => $record?->uuid,
                    'birth_weight' => $item['weight'] ?? null,
                ]), fn ($value) => $value !== null),
                'revision' => 1,
                'created_by_uuid' => $actor->uuid,
                'updated_by_uuid' => $actor->uuid,
            ]);
        }
    }

    private function splitGroup(AnimalSubject $subject, array $roles, User $actor): void
    {
        $this->assertGroup($subject);
        $quantity = $roles['split_quantity'] ?? null;
        if (! is_int($quantity) || $quantity < 1 || $quantity >= (int) $subject->quantity) {
            throw ValidationException::withMessages(['split_quantity' => 'Split quantity must be smaller than the current group.']);
        }
        $subject->quantity = ((int) $subject->quantity) - $quantity;
        AnimalSubject::create([
            'uuid' => $roles['new_group_uuid'] ?? (string) Str::uuid(),
            'farm_uuid' => $subject->farm_uuid,
            'species_uuid' => $subject->species_uuid,
            'blueprint_uuid' => $subject->blueprint_uuid,
            'blueprint_version' => $subject->blueprint_version,
            'unit_type' => 'group',
            'display_name' => $roles['new_group_name'] ?? null,
            'birth_or_start_date' => now()->toDateString(),
            'sex_code' => 'not_applicable',
            'quantity' => $quantity,
            'quantity_unit' => $subject->quantity_unit,
            'lifecycle_status' => 'active',
            'attributes_json' => ['split_from_uuid' => $subject->uuid],
            'revision' => 1,
            'created_by_uuid' => $actor->uuid,
            'updated_by_uuid' => $actor->uuid,
        ]);
    }

    private function mergeGroups(AnimalSubject $subject, array $roles, User $actor): void
    {
        $this->assertGroup($subject);
        $sourceUuids = $roles['source_group_uuids'] ?? null;
        if (is_string($sourceUuids)) {
            $sourceUuids = [$sourceUuids];
        }
        if (! is_array($sourceUuids) || $sourceUuids === []) {
            throw ValidationException::withMessages(['source_group_uuids' => 'Select at least one source group.']);
        }
        $sources = AnimalSubject::query()->whereIn('uuid', $sourceUuids)->lockForUpdate()->get();
        if ($sources->count() !== count(array_unique($sourceUuids))) {
            throw ValidationException::withMessages(['source_group_uuids' => 'One or more source groups do not exist.']);
        }
        foreach ($sources as $source) {
            $this->assertGroup($source);
            if ($source->uuid === $subject->uuid || $source->farm_uuid !== $subject->farm_uuid || $source->blueprint_uuid !== $subject->blueprint_uuid) {
                throw ValidationException::withMessages(['source_group_uuids' => 'Groups must be distinct and use the same farm and blueprint.']);
            }
            $subject->quantity = ((int) $subject->quantity) + ((int) $source->quantity);
            $source->forceFill([
                'quantity' => 0,
                'lifecycle_status' => 'merged',
                'revision' => ((int) $source->revision) + 1,
                'updated_by_uuid' => $actor->uuid,
            ])->save();
        }
    }

    private function assignIdentifier(AnimalSubject $subject, array $roles, User $actor): void
    {
        $typeCode = $roles['identifier_type_code'] ?? null;
        $value = $roles['identifier_value'] ?? null;
        if (! is_string($typeCode) || ! is_string($value)) {
            throw ValidationException::withMessages(['identifier' => 'Identifier type and value are required.']);
        }
        $typeCode === 'paired_rfid_barcode'
            ? $this->identifiers->assignPairedBarcodeRfid($subject, $value, $value, $actor)
            : $this->identifiers->assign($subject, $typeCode, $value, $actor);
    }

    private function retireIdentifier(AnimalSubject $subject, array $roles, User $actor): void
    {
        $uuid = $roles['identifier_uuid'] ?? null;
        if (! is_string($uuid)) {
            throw ValidationException::withMessages(['identifier_uuid' => 'Identifier UUID is required.']);
        }
        $this->identifiers->retire($subject, $uuid, $actor);
    }

    private function isGroup(AnimalSubject $subject): bool
    {
        return $subject->unit_type->value === 'group';
    }

    private function assertGroup(AnimalSubject $subject): void
    {
        if (! $this->isGroup($subject)) {
            throw ValidationException::withMessages(['subject' => 'This behavior requires a group subject.']);
        }
    }
}
