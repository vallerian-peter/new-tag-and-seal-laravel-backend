<?php

namespace App\Services\Upgrade;

use App\Enums\DefinitionStatus;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\EventDefinition;
use App\Models\FormDefinition;
use App\Models\IdentifierType;
use App\Services\Dynamic\ConfigurationSyncService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class DynamicV1Installer
{
    private const NAMESPACE = 'https://tag-and-seal.local/dynamic-v1/';

    public function __construct(private readonly ConfigurationSyncService $configuration) {}

    public function install(?string $actorUuid = null): array
    {
        return DB::transaction(function () use ($actorUuid): array {
            $this->installIdentifierTypes();
            $installed = [];
            foreach ($this->speciesCatalog() as $code => $definition) {
                $installed[] = $this->installSpeciesBlueprint($code, $definition, $actorUuid);
            }
            $this->configuration->publishRevision($actorUuid);

            return $installed;
        });
    }

    public function installLegacyHistoryAdapters(): int
    {
        $formUuid = $this->uuid('form/legacy.history/1');
        $schema = [
            'allows_system_only_form' => true,
            'sections' => [[
                'key' => 'legacy_history',
                'label' => ['en' => 'Legacy history'],
                'fields' => [],
            ]],
        ];
        FormDefinition::query()->updateOrCreate(['uuid' => $formUuid], [
            'code' => 'legacy.history',
            'version' => 1,
            'purpose' => 'event',
            'status' => DefinitionStatus::PUBLISHED->value,
            'system_envelope_version' => 1,
            'schema_json' => $schema,
            'checksum' => 'sha256:'.hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
            'minimum_app_schema_version' => 1,
            'published_at' => now(),
        ]);

        $count = 0;
        foreach (AnimalBlueprint::query()->where('version', 1)->get() as $blueprint) {
            EventDefinition::query()->updateOrCreate([
                'blueprint_uuid' => $blueprint->uuid,
                'blueprint_version' => 1,
                'code' => 'legacy_history',
            ], [
                'uuid' => $this->uuid("event/{$blueprint->code}/legacy_history/1"),
                'name' => 'Legacy history',
                'form_uuid' => $formUuid,
                'form_version' => 1,
                'behavior_code' => 'record_only',
                'sort_order' => 9999,
                'status' => DefinitionStatus::RETIRED->value,
            ]);
            $count++;
        }

        // Publish after installing compatibility adapters so mobile clients receive
        // a checksum generated from the final immutable manifest shape.
        $this->configuration->publishRevision();

        return $count;
    }

    public function uuid(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::NAMESPACE.$key)->toString();
    }

    private function installSpeciesBlueprint(string $code, array $definition, ?string $actorUuid): string
    {
        $speciesUuid = $this->uuid("species/$code");
        AnimalSpecies::query()->updateOrCreate(['uuid' => $speciesUuid], [
            'code' => $code,
            'name' => $definition['name'],
            'translations_json' => ['en' => $definition['name'], 'sw' => $definition['sw']],
            'category_code' => $definition['category'],
            'default_subject_mode' => count($definition['modes']) === 2 ? 'both' : $definition['modes'][0],
            'group_display_name' => $definition['group'],
            'status' => DefinitionStatus::PUBLISHED->value,
            'created_by_uuid' => $actorUuid,
            'published_at' => now(),
        ]);

        $registrationCode = "$code.registration";
        $registrationUuid = $this->uuid("form/$registrationCode/1");
        $registrationSchema = $this->registrationSchema($definition['modes']);
        FormDefinition::query()->updateOrCreate(['uuid' => $registrationUuid], [
            'code' => $registrationCode,
            'version' => 1,
            'purpose' => 'registration',
            'status' => DefinitionStatus::PUBLISHED->value,
            'system_envelope_version' => 1,
            'schema_json' => $registrationSchema,
            'checksum' => 'sha256:'.hash('sha256', json_encode($registrationSchema, JSON_THROW_ON_ERROR)),
            'minimum_app_schema_version' => 1,
            'created_by_uuid' => $actorUuid,
            'published_at' => now(),
        ]);

        $blueprintUuid = $this->uuid("blueprint/$code/1");
        AnimalBlueprint::query()->updateOrCreate(['uuid' => $blueprintUuid], [
            'species_uuid' => $speciesUuid,
            'code' => "$code.standard",
            'version' => 1,
            'status' => DefinitionStatus::PUBLISHED->value,
            'registration_form_uuid' => $registrationUuid,
            'registration_form_version' => 1,
            'settings_json' => [
                'subjectModes' => $definition['modes'],
                'allowedSexCodes' => ['male', 'female', 'intersex', 'unknown', 'not_applicable'],
                'reproductionMode' => $definition['reproduction'],
                'identificationPolicy' => ['allowed' => ['paired_rfid_barcode', 'visual_ear_tag', 'batch_code', 'flock_code', 'hive_code', 'pond_code'], 'required' => [], 'allowWithoutTag' => true],
                'locales' => ['en', 'sw'],
            ],
            'compiled_manifest_json' => ['speciesCode' => $code, 'subjectModes' => $definition['modes']],
            'checksum' => 'sha256:'.hash('sha256', "$code:1"),
            'created_by_uuid' => $actorUuid,
            'published_by_uuid' => $actorUuid,
            'published_at' => now(),
        ]);

        $this->installCommonEvents($blueprintUuid, $code, $definition, $actorUuid);

        return $blueprintUuid;
    }

    private function installCommonEvents(string $blueprintUuid, string $speciesCode, array $definition, ?string $actorUuid): void
    {
        $events = [
            ['record_weight', 'Record weight', 'update_weight', [['weight', 'Weight', 'decimal', true]]],
            ['record_vaccination', 'Record vaccination', 'record_only', [['vaccine', 'Vaccine', 'text', true], ['remarks', 'Remarks', 'multiline_text', false]]],
        ];
        if (in_array($speciesCode, ['cattle', 'goat', 'sheep'], true)) {
            $events[] = ['record_milking', 'Milking', 'record_production_yield', [
                ['amount', 'Milk quantity (L)', 'decimal', true],
                ['session', 'Session', 'single_select', false],
            ]];
            if (in_array($speciesCode, ['goat', 'sheep'], true)) {
                $events[] = ['record_fiber', 'Fiber (Wool)', 'record_production_yield', [
                    ['amount', 'Weight (kg)', 'decimal', true],
                    ['fiber_grade', 'Grade / Quality', 'text', false],
                    ['remarks', 'Remarks', 'multiline_text', false],
                ]];
            }
        } elseif ($speciesCode === 'chicken') {
            $events[] = ['record_egg_collection', 'Record eggs', 'record_egg_or_clutch', [
                ['production_date', 'Production date', 'date', true],
                ['collection_session', 'Collection session', 'single_select', true],
                ['total_eggs', 'Total eggs', 'integer', true],
                ['broken_eggs', 'Broken eggs', 'integer', false],
                ['dirty_eggs', 'Dirty eggs', 'integer', false],
                ['remarks', 'Remarks', 'multiline_text', false],
            ]];
        } else {
            $events[] = ['record_production', 'Record production', 'record_production_yield', [['quantity', 'Quantity', 'decimal', true], ['unit', 'Unit', 'text', true]]];
        }

        foreach ($events as $sort => [$code, $name, $behavior, $fields]) {
            $formCode = "$speciesCode.event.$code";
            $formUuid = $this->uuid("form/$formCode/1");
            $schema = $this->eventSchema($formCode, $fields);
            FormDefinition::query()->updateOrCreate(['uuid' => $formUuid], [
                'code' => $formCode,
                'version' => 1,
                'purpose' => 'event',
                'status' => DefinitionStatus::PUBLISHED->value,
                'system_envelope_version' => 1,
                'schema_json' => $schema,
                'checksum' => 'sha256:'.hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
                'minimum_app_schema_version' => 1,
                'created_by_uuid' => $actorUuid,
                'published_at' => now(),
            ]);
            EventDefinition::query()->updateOrCreate([
                'blueprint_uuid' => $blueprintUuid,
                'blueprint_version' => 1,
                'code' => $code,
            ], [
                'uuid' => $this->uuid("event/$speciesCode/$code/1"),
                'name' => $name,
                'translations_json' => ['en' => $name],
                'form_uuid' => $formUuid,
                'form_version' => 1,
                'behavior_code' => $behavior,
                'eligibility_rule_json' => ['all' => [['fact' => 'subject.lifecycle_status', 'operator' => 'equals', 'value' => 'active']]],
                'repeatability_rule_json' => null,
                'result_rule_json' => null,
                'sort_order' => $sort,
                'status' => DefinitionStatus::PUBLISHED->value,
            ]);
        }
    }

    private function registrationSchema(array $modes): array
    {
        return ['sections' => [[
            'key' => 'basic_information',
            'label' => ['en' => 'Basic information', 'sw' => 'Taarifa za msingi'],
            'fields' => [
                $this->field('display_name', 'Display name', 'text', 'string', false),
                $this->field('breed_name', 'Breed', 'text', 'string', false),
            ],
        ]], 'subject_modes' => $modes];
    }

    private function eventSchema(string $formCode, array $fields): array
    {
        return ['sections' => [[
            'key' => 'event_details',
            'label' => ['en' => 'Event details'],
            'fields' => array_map(fn ($field) => $this->field($field[0], $field[1], $field[2], match ($field[2]) {
                'integer' => 'integer', 'decimal' => 'decimal', 'date' => 'date', default => 'string'
            }, $field[3]), $fields),
        ]], 'form_code' => $formCode];
    }

    private function field(string $key, string $label, string $component, string $valueType, bool $required): array
    {
        $validation = in_array($valueType, ['integer', 'decimal'], true) ? ['min' => 0] : [];
        if ($key === 'collection_session') {
            $validation['allowed_values'] = ['morning', 'afternoon', 'evening', 'total_day'];
        }
        if ($key === 'session') {
            $validation['allowed_values'] = ['morning', 'afternoon', 'evening', 'night'];
        }

        $field = [
            'field_uuid' => $this->uuid("field/$key"),
            'key' => $key,
            'label' => ['en' => $label],
            'component' => $component,
            'value_type' => $valueType,
            'data_owner' => 'user_input',
            'required' => $required ? 'always' : 'optional',
            'nullable' => ! $required,
            'validation' => $validation,
            'sort_order' => 0,
        ];
        if (isset($validation['allowed_values'])) {
            $field['options'] = array_map(fn (string $value) => [
                'value' => $value,
                'label' => ['en' => str($value)->replace('_', ' ')->title()->toString()],
            ], $validation['allowed_values']);
        }

        return $field;
    }

    private function installIdentifierTypes(): void
    {
        $types = [
            'visual_ear_tag', 'paired_rfid_barcode', 'rfid_epc', 'barcode', 'qr_code',
            'microchip', 'leg_band', 'wing_band', 'flock_code', 'batch_code',
            'house_code', 'collar_tag', 'brand_mark', 'tattoo', 'fin_clip_code',
            'pond_code', 'tank_code', 'colony_code', 'hive_code',
        ];
        foreach ($types as $code) {
            IdentifierType::query()->updateOrCreate(['code' => $code], [
                'uuid' => $this->uuid("identifier-type/$code"),
                'name' => str($code)->replace('_', ' ')->title()->toString(),
                'translations_json' => null,
                'supported_subject_modes' => ['individual', 'group'],
                'format_rule' => null,
                'normalization_rule' => 'trim_uppercase',
                'uniqueness_scope' => 'global',
                'status' => 'active',
            ]);
        }
    }

    private function speciesCatalog(): array
    {
        return [
            'cattle' => ['name' => 'Cattle', 'sw' => 'Ng’ombe', 'category' => 'mammal', 'modes' => ['individual', 'group'], 'group' => 'Herd', 'reproduction' => 'live_birth'],
            'pig' => ['name' => 'Pig', 'sw' => 'Nguruwe', 'category' => 'mammal', 'modes' => ['individual', 'group'], 'group' => 'Herd', 'reproduction' => 'live_birth'],
            'goat' => ['name' => 'Goat', 'sw' => 'Mbuzi', 'category' => 'mammal', 'modes' => ['individual', 'group'], 'group' => 'Herd', 'reproduction' => 'live_birth'],
            'sheep' => ['name' => 'Sheep', 'sw' => 'Kondoo', 'category' => 'mammal', 'modes' => ['individual', 'group'], 'group' => 'Flock', 'reproduction' => 'live_birth'],
            'chicken' => ['name' => 'Chicken', 'sw' => 'Kuku', 'category' => 'poultry', 'modes' => ['individual', 'group'], 'group' => 'Flock', 'reproduction' => 'egg_laying'],
            'rabbit' => ['name' => 'Rabbit', 'sw' => 'Sungura', 'category' => 'mammal', 'modes' => ['individual', 'group'], 'group' => 'Colony', 'reproduction' => 'live_birth'],
            'fish' => ['name' => 'Fish', 'sw' => 'Samaki', 'category' => 'aquaculture', 'modes' => ['individual', 'group'], 'group' => 'Population', 'reproduction' => 'spawning'],
            'bee' => ['name' => 'Bee', 'sw' => 'Nyuki', 'category' => 'apiculture', 'modes' => ['group'], 'group' => 'Colony', 'reproduction' => 'colony_split'],
        ];
    }
}
