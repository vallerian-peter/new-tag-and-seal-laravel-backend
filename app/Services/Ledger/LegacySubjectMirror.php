<?php

namespace App\Services\Ledger;

use App\Enums\DefinitionStatus;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one subject per legacy livestock row. Only the columns the legacy app owns are
 * written; registration answers, identifiers and ledger-derived attributes are kept.
 */
class LegacySubjectMirror
{
    public function __construct(
        private readonly DynamicV1Installer $installer,
        private readonly LegacyLookups $lookups,
    ) {}

    public function mirrorUuid(?string $uuid): ?AnimalSubject
    {
        $row = $uuid ? DB::table('livestocks')->where('uuid', $uuid)->first() : null;

        return $row ? $this->mirror((array) $row) : null;
    }

    /** @param  array<string, mixed>  $legacy  a livestocks row */
    public function mirror(array $legacy): ?AnimalSubject
    {
        $speciesName = DB::table('species')->where('id', $legacy['speciesId'] ?? null)->value('name');
        $species = AnimalSpecies::query()->where('code', self::canonicalSpeciesCode((string) $speciesName))->first();
        if (! $species) {
            return null;
        }
        $uuid = $legacy['uuid'] ?: $this->installer->uuid('legacy/livestocks/'.$legacy['id']);
        $subject = AnimalSubject::withTrashed()->where('uuid', $uuid)->first();
        if (! $subject) {
            $blueprint = AnimalBlueprint::query()->where('species_uuid', $species->uuid)->where('status', DefinitionStatus::PUBLISHED->value)->orderByDesc('version')->first();
            if (! $blueprint) {
                return null;
            }
            $subject = new AnimalSubject([
                'uuid' => $uuid,
                'species_uuid' => $species->uuid,
                'blueprint_uuid' => $blueprint->uuid,
                'blueprint_version' => $blueprint->version,
                'unit_type' => 'individual',
                'attributes_json' => [],
                'revision' => max(1, (int) ($legacy['revision'] ?? 1)),
                'created_at' => $legacy['created_at'] ?? now(),
            ]);
        }

        $attributes = array_merge($subject->attributes_json ?? [], array_filter([
            'legacyId' => $legacy['id'],
            'identificationNumber' => $legacy['identificationNumber'] ?? null,
            'breedId' => $legacy['breedId'] ?? null,
            'livestockTypeId' => $legacy['livestockTypeId'] ?? null,
            'weightAsOnRegistration' => $legacy['weightAsOnRegistration'] ?? null,
            'mother_uuid' => $legacy['motherUuid'] ?? null,
            'father_uuid' => $legacy['fatherUuid'] ?? null,
            'birth_event_uuid' => $this->birthEventUuid($legacy['birthEventUuid'] ?? null),
        ], fn ($value) => $value !== null && $value !== ''));
        $stage = $this->lookups->code('stages', $species->code, $legacy['stageId'] ?? null);

        $subject->fill([
            'farm_uuid' => $legacy['farmUuid'],
            'display_name' => $legacy['name'],
            'birth_or_start_date' => $legacy['dateOfBirth'],
            'sex_code' => in_array($legacy['gender'] ?? null, ['male', 'female'], true) ? $legacy['gender'] : 'unknown',
            'lifecycle_status' => self::lifecycle((string) ($legacy['status'] ?? 'active')),
            'attributes_json' => $attributes,
            'deleted_at' => $legacy['deleted_at'] ?? null,
            'deleted_by_uuid' => $legacy['deleted_by_uuid'] ?? null,
            'delete_reason' => $legacy['delete_reason'] ?? null,
        ]);
        if ($stage !== null) {
            $subject->current_stage_code = $stage;
        }
        if ($subject->exists && $subject->isDirty()) {
            $subject->revision = ((int) $subject->revision) + 1;
        }
        if (! $subject->exists || $subject->isDirty()) {
            $subject->save();
        }
        DB::table('legacy_record_mappings')->updateOrInsert([
            'source_table' => 'livestocks', 'source_key' => (string) $legacy['id'], 'target_type' => 'animal_subject',
        ], ['source_uuid' => $legacy['uuid'] ?? null, 'target_uuid' => $uuid, 'target_version' => 1, 'updated_at' => now(), 'created_at' => now()]);

        return $subject;
    }

    public static function canonicalSpeciesCode(string $name): string
    {
        $normalized = str($name)->lower()->ascii()->toString();

        return match (true) {
            str_contains($normalized, 'cattle'), str_contains($normalized, 'cow') => 'cattle',
            str_contains($normalized, 'pig'), str_contains($normalized, 'swine') => 'pig',
            str_contains($normalized, 'goat') => 'goat',
            str_contains($normalized, 'sheep') => 'sheep',
            str_contains($normalized, 'chicken'), str_contains($normalized, 'poultry') => 'chicken',
            str_contains($normalized, 'rabbit') => 'rabbit',
            str_contains($normalized, 'fish') => 'fish',
            str_contains($normalized, 'bee') => 'bee',
            default => str($normalized)->slug('_')->toString(),
        };
    }

    public static function lifecycle(string $status): string
    {
        return match ($status) {
            'notActive', 'not-active', 'not_active', 'inactive' => 'inactive',
            'dead', 'sold', 'transferred', 'disposed' => $status,
            default => 'active',
        };
    }

    private function birthEventUuid(?string $legacyUuid): ?string
    {
        if (! $legacyUuid) {
            return null;
        }

        return DB::table('legacy_record_mappings')->where('source_table', 'birth_events')->where('source_uuid', $legacyUuid)
            ->where('target_type', 'event_record')->value('target_uuid') ?? $legacyUuid;
    }
}
