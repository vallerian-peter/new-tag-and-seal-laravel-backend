<?php

namespace App\Services\Ledger;

use App\Enums\DefinitionStatus;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\EventDefinition;
use App\Models\FormDefinition;
use App\Services\Dynamic\BehaviorCatalog;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Dynamic\FormSchemaValidator;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Installs the configured events that replace each legacy log screen: one form per
 * species and event whose fields mirror the legacy columns, published on every
 * published blueprint of that species.
 */
class LedgerEventInstaller
{
    public function __construct(
        private readonly DynamicV1Installer $installer,
        private readonly LegacyLookups $lookups,
        private readonly BehaviorCatalog $catalog,
        private readonly FormSchemaValidator $forms,
        private readonly ConfigurationSyncService $configuration,
    ) {}

    /** @param  array<int, string>|null  $families  null installs every family */
    public function install(?array $families = null, ?string $actorUuid = null): array
    {
        return DB::transaction(function () use ($families, $actorUuid): array {
            $lookups = $this->lookups->mirror();
            $installed = [];
            foreach (LegacyCatalog::tables() as $table => $spec) {
                if ($families !== null && ! in_array($spec['family'], $families, true)) {
                    continue;
                }
                foreach ($spec['events'] as $sort => $event) {
                    foreach ($event['species'] as $speciesCode) {
                        foreach ($this->blueprints($speciesCode) as [$species, $blueprint]) {
                            $installed[] = $this->installEvent($table, $spec, $event, $species, $blueprint, 100 + $sort, $actorUuid);
                        }
                    }
                }
            }
            $revision = $this->configuration->publishRevision($actorUuid);

            return ['lookupOptions' => $lookups, 'events' => count($installed), 'revision' => $revision->revision ?? null];
        });
    }

    /** The form schema of one event for one species (also used by reconciliation). */
    public function schema(string $table, array $spec, array $event, string $speciesCode): array
    {
        $formCode = "$speciesCode.legacy.{$event['code']}";
        $fields = [];
        foreach ($spec['fields'] as $index => $field) {
            $fields[] = $this->field($formCode, $field, $speciesCode, $index);
        }
        if ($spec['offspring'] ?? false) {
            $fields[] = $this->offspringField($formCode, count($fields));
        }

        return [
            'sections' => [[
                'key' => 'event_details',
                'label' => ['en' => 'Event details', 'sw' => 'Taarifa za tukio'],
                'fields' => $fields,
            ]],
            'form_code' => $formCode,
            'legacy_table' => $table,
        ];
    }

    private function installEvent(string $table, array $spec, array $event, AnimalSpecies $species, AnimalBlueprint $blueprint, int $sort, ?string $actorUuid): string
    {
        $schema = $this->schema($table, $spec, $event, $species->code);
        $this->forms->assertDefinition($schema);
        $modes = $blueprint->settings_json['subjectModes'] ?? $species->capabilities_json['subjectModes'] ?? ['individual'];
        $errors = $this->catalog->validateEvent($event['behavior'], $schema, $modes, $species->capabilities_json);
        if ($errors !== []) {
            throw new RuntimeException("{$species->code}.{$event['code']}: ".json_encode($errors, JSON_THROW_ON_ERROR));
        }

        $formCode = $schema['form_code'];
        $checksum = 'sha256:'.hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
        $latest = FormDefinition::query()->where('code', $formCode)->orderByDesc('version')->first();
        if (! $latest || $latest->checksum !== $checksum) {
            $version = ($latest->version ?? 0) + 1;
            $latest = FormDefinition::query()->create([
                'uuid' => $this->installer->uuid("form/$formCode/$version"),
                'code' => $formCode,
                'version' => $version,
                'purpose' => 'event',
                'status' => DefinitionStatus::PUBLISHED->value,
                'system_envelope_version' => 1,
                'schema_json' => $schema,
                'checksum' => $checksum,
                'minimum_app_schema_version' => 1,
                'created_by_uuid' => $actorUuid,
                'published_at' => now(),
            ]);
        }

        $eligibility = [['fact' => 'subject.lifecycle_status', 'operator' => 'equals', 'value' => 'active']];
        if ($event['sex']) {
            $eligibility[] = ['fact' => 'subject.sex_code', 'operator' => 'in', 'value' => [$event['sex'], 'unknown']];
        }
        $existing = EventDefinition::query()->where('blueprint_uuid', $blueprint->uuid)->where('blueprint_version', $blueprint->version)->where('code', $event['code'])->first();
        EventDefinition::query()->updateOrCreate([
            'blueprint_uuid' => $blueprint->uuid,
            'blueprint_version' => $blueprint->version,
            'code' => $event['code'],
        ], [
            'uuid' => $existing->uuid ?? $this->installer->uuid("event/{$species->code}/{$event['code']}/{$blueprint->version}"),
            'name' => $event['name'],
            'translations_json' => ['en' => $event['name'], 'sw' => $event['sw']],
            'form_uuid' => $latest->uuid,
            'form_version' => $latest->version,
            'behavior_code' => $event['behavior'],
            'family' => $spec['family'],
            'eligibility_rule_json' => ['all' => $eligibility],
            'repeatability_rule_json' => null,
            'result_rule_json' => ['legacyLogType' => $event['legacyLogType'], 'legacyTable' => $table],
            'sort_order' => $sort,
            'status' => DefinitionStatus::PUBLISHED->value,
        ]);

        return "{$species->code}.{$event['code']}";
    }

    /** @return array<int, array{0: AnimalSpecies, 1: AnimalBlueprint}> */
    private function blueprints(string $speciesCode): array
    {
        $species = AnimalSpecies::query()->where('code', $speciesCode)->first();
        if (! $species) {
            return [];
        }

        return AnimalBlueprint::query()->where('species_uuid', $species->uuid)->where('status', DefinitionStatus::PUBLISHED->value)
            ->orderBy('version')->get()->map(fn ($blueprint) => [$species, $blueprint])->all();
    }

    private function field(string $formCode, array $field, string $speciesCode, int $index): array
    {
        $component = $field['component'];
        $numeric = in_array($component, ['integer', 'decimal', 'measurement'], true);
        $schema = [
            'field_uuid' => $this->installer->uuid("field/$formCode/{$field['key']}"),
            'key' => $field['key'],
            'label' => array_filter($field['label'], fn ($text) => is_string($text) && $text !== ''),
            'component' => $component,
            'value_type' => match ($component) {
                'integer' => 'integer',
                'decimal', 'measurement' => 'decimal',
                'date' => 'date',
                'date_time' => 'date_time',
                'multi_select' => 'array',
                default => 'string',
            },
            'data_owner' => 'user_input',
            'required' => $field['required'] ? 'always' : 'optional',
            'nullable' => ! $field['required'],
            'validation' => $numeric ? ['min' => 0] : [],
            'sort_order' => $index,
        ];
        if ($field['column']) {
            $schema['legacy_column'] = $field['column'];
        }
        if (isset($field['role'])) {
            $schema['role'] = $field['role'];
        }
        if (isset($field['unit'])) {
            $schema['unit'] = $field['unit'];
        }
        if (isset($field['lookup'])) {
            $schema['lookup_code'] = LegacyLookups::setCode($field['lookup'], $speciesCode);
        }
        if (isset($field['options'])) {
            $schema['options'] = $field['options'];
            $schema['validation']['allowed_values'] = array_column($field['options'], 'value');
        }
        if (isset($field['constant'])) {
            $schema['default_value'] = $field['constant'];
            $schema['legacy_constant'] = true;
        }

        return $schema;
    }

    private function offspringField(string $formCode, int $index): array
    {
        $item = fn (string $key, array $label, string $component, bool $required, array $extra = []) => [
            'field_uuid' => $this->installer->uuid("field/$formCode/offspring/$key"),
            'key' => $key,
            'label' => $label,
            'component' => $component,
            'value_type' => $component === 'measurement' ? 'decimal' : 'string',
            'required' => $required ? 'always' : 'optional',
            'nullable' => ! $required,
        ] + $extra;

        return [
            'field_uuid' => $this->installer->uuid("field/$formCode/offspring"),
            'key' => 'offspring',
            'label' => ['en' => 'Offspring', 'sw' => 'Watoto'],
            'component' => 'repeatable_group',
            'value_type' => 'array',
            'data_owner' => 'user_input',
            'required' => 'optional',
            'nullable' => true,
            'role' => 'offspring',
            'validation' => [],
            'sort_order' => $index,
            'item_fields' => [
                $item('display_name', ['en' => 'Name', 'sw' => 'Jina'], 'text', true),
                $item('sex_code', ['en' => 'Sex', 'sw' => 'Jinsia'], 'single_select', true, [
                    'options' => [
                        ['value' => 'female', 'label' => ['en' => 'Female', 'sw' => 'Jike']],
                        ['value' => 'male', 'label' => ['en' => 'Male', 'sw' => 'Dume']],
                    ],
                    'validation' => ['allowed_values' => ['female', 'male']],
                ]),
                $item('weight', ['en' => 'Birth weight', 'sw' => 'Uzito wa kuzaliwa'], 'measurement', false, ['unit' => 'kg', 'validation' => ['min' => 0]]),
            ],
        ];
    }
}
